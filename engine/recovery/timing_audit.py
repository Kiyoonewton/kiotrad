"""Read-only audit of legacy odds coverage and verified fixture timing.

Accepts original JSON or KioTrad body_base64 raw envelopes. Never writes to a
provider archive, MongoDB or Redis, and never treats response times as kickoff.
"""
import argparse
import base64
import gzip
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

LEAGUES = {"31867": "virtual-football", "34616": "virtual-bundesliga", "14149": "virtual-euro"}
ROUTE = re.compile(r"vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$")


def read_payload(path):
    body = gzip.decompress(path.read_bytes()) if path.name.endswith(".gz") else path.read_bytes()
    payload = json.loads(body)
    if isinstance(payload, dict) and "body_base64" in payload:
        payload = json.loads(base64.b64decode(payload["body_base64"], validate=True))
    return payload


def scan(roots, coverage=None):
    odds = defaultdict(set)
    times = defaultdict(lambda: defaultdict(set))
    counts = Counter()
    for root in roots:
        for path in Path(root).rglob("*"):
            if not path.is_file() or not path.name.endswith((".json", ".json.gz")):
                continue
            if path.is_symlink():
                continue
            counts["files"] += 1
            if counts["files"] % 1000 == 0:
                print(f"Scanned {counts['files']} files...", file=sys.stderr, flush=True)
            try:
                payload = read_payload(path)
                if not isinstance(payload, dict):
                    continue
                route = ROUTE.fullmatch(str(payload.get("queryUrl", "")))
                if coverage is not None:
                    coverage.collect(payload, route)
                docs = payload.get("doc", [])
                if not isinstance(docs, list):
                    raise ValueError("invalid doc array")
                for doc in docs:
                    if not isinstance(doc, dict) or not isinstance(doc.get("data"), dict):
                        continue
                    data = doc["data"]
                    if route:
                        season, day = route.group(1), int(route.group(2))
                        if not 1 <= day <= 30:
                            continue
                        for event in data.get("odds", []):
                            league = LEAGUES.get(str(event.get("tournament_id", "")).split(":")[-1])
                            if league and event.get("id"):
                                odds[(league, season)].add((day, str(event["id"])))
                    if doc.get("event") != "stats_season_fixtures":
                        continue
                    for match in data.get("matches", []):
                        league = LEAGUES.get(str(match.get("_utid", data.get("_utid", ""))))
                        season = str(match.get("_seasonid", data.get("_id", "")))
                        day = match.get("round")
                        uts = (match.get("time") or {}).get("uts")
                        if league and season.isdigit() and type(day) is int and 1 <= day <= 30 and type(uts) is int and uts > 0:
                            times[(league, season)][day].add(uts)
            except (ValueError, TypeError, KeyError, AttributeError, OSError, EOFError):
                counts["malformed"] += 1
    return odds, times, counts


def assess(odds, times, counts):
    rows = []
    for league, season in sorted(set(odds) | set(times), key=lambda key: (key[0], int(key[1]))):
        days = times.get((league, season), {})
        conflicts = sorted(day for day, values in days.items() if len(values) != 1)
        verified = {day: next(iter(values)) for day, values in days.items() if len(values) == 1}
        errors, cadence = [], []
        ordered = sorted(verified)
        for left, right in zip(ordered, ordered[1:]):
            cadence.append((verified[right] - verified[left]) / (right - left))
        # Hold each interior day out: its own timestamp never enters prediction.
        for left, held, right in zip(ordered, ordered[1:], ordered[2:]):
            predicted = verified[left] + (verified[right] - verified[left]) * (held - left) / (right - left)
            errors.append(abs(predicted - verified[held]))
        rows.append({
            "competition": league, "season_id": season,
            "combined_odds_match_ids": len(odds.get((league, season), set())),
            "verified_days": len(verified), "conflicting_days": conflicts,
            "day1_utc_epoch": verified.get(1),
            "matchday_spacing_seconds": sorted(set(cadence)),
            "held_out_days": len(errors),
            "max_interpolation_error_seconds": max(errors) if errors else None,
            "status": "conflicting_timing" if conflicts else "verified_anchor_available" if 1 in verified else "day1_anchor_missing",
        })
    return {"version": "historical-timing-audit-2.0.0", "scan": dict(counts), "seasons": rows,
            "limits": ["No timestamps estimated or persisted by this audit.",
                       "Legacy match-ID coverage does not establish complete odds or results.",
                       "Cross-season extrapolation requires independently verified consecutive links; numeric IDs and discovery-list order are insufficient.",
                       "Within-season held-out errors do not establish historical cross-league overlap accuracy.",
                       "Provider time.uts is scheduled kickoff, not actual start or settlement."]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("legacy", help="Original my_trade raw directory")
    parser.add_argument("kiotrad", help="KioTrad raw snapshot directory")
    args = parser.parse_args()
    roots = [Path(args.legacy).resolve(), Path(args.kiotrad).resolve()]
    if any(not root.is_dir() for root in roots):
        parser.error("Both inputs must be existing directories")
    if roots[0] == roots[1] or roots[0] in roots[1].parents or roots[1] in roots[0].parents:
        parser.error("Use separate, non-nested source directories")
    from source_coverage import Coverage, compare_sources
    legacy, kiotrad = Coverage(), Coverage()
    _, _, old_counts = scan([roots[0]], legacy)
    odds, times, current_counts = scan([roots[1]], kiotrad)
    report = assess(odds, times, current_counts)
    report["scan"] = {"legacy": dict(old_counts), "kiotrad": dict(current_counts)}
    timing = {(row["competition"], row["season_id"]): row for row in report["seasons"]}
    rows = compare_sources(legacy, kiotrad)
    for row in rows:
        row["kiotrad_timing"] = timing.get((row["competition"], row["season_id"]), {
            "verified_days": 0, "status": "day1_anchor_missing"})
    report["seasons"] = rows
    report["source_roots"] = {"legacy": str(roots[0]), "kiotrad": str(roots[1])}
    report["limits"].extend([
        "Partial seasons are retained; missing evidence is not a loss or confirmed blockage.",
        "Multiple historical prices can be legitimate changes, not a data conflict.",
        "Source presence means evidence in the supplied snapshot, not a live database audit.",
        "Coverage checks do not certify season continuity or full fixture integrity.",
    ])
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
