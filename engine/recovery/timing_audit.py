"""Read-only audit of legacy odds coverage and verified fixture timing.

Accepts original JSON or KioTrad body_base64 raw envelopes. Never writes to a
provider archive, MongoDB or Redis, and never treats response times as kickoff.
"""
import argparse
import base64
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

LEAGUES = {"31867": "virtual-football", "34616": "virtual-bundesliga", "14149": "virtual-euro"}
ROUTE = re.compile(r"vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$")


def read_payload(path):
    payload = json.loads(path.read_bytes())
    if isinstance(payload, dict) and "body_base64" in payload:
        payload = json.loads(base64.b64decode(payload["body_base64"], validate=True))
    return payload


def scan(roots):
    odds = defaultdict(set)
    times = defaultdict(lambda: defaultdict(set))
    counts = Counter()
    for root in roots:
        for path in Path(root).rglob("*.json"):
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
            except (ValueError, TypeError, KeyError, AttributeError):
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
            "legacy_match_ids": len(odds.get((league, season), set())),
            "verified_days": len(verified), "conflicting_days": conflicts,
            "day1_utc_epoch": verified.get(1),
            "matchday_spacing_seconds": sorted(set(cadence)),
            "held_out_days": len(errors),
            "max_interpolation_error_seconds": max(errors) if errors else None,
            "status": "conflicting_timing" if conflicts else "verified_anchor_available" if 1 in verified else "day1_anchor_missing",
        })
    return {"version": "historical-timing-audit-1.0.0", "scan": dict(counts), "seasons": rows,
            "limits": ["No timestamps estimated or persisted by this audit.",
                       "Legacy match-ID coverage does not establish complete odds or results.",
                       "Cross-season extrapolation requires independently verified consecutive links; numeric IDs and discovery-list order are insufficient.",
                       "Within-season held-out errors do not establish historical cross-league overlap accuracy.",
                       "Provider time.uts is scheduled kickoff, not actual start or settlement."]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("roots", nargs="+", help="Legacy raw and KioTrad raw snapshot directories")
    args = parser.parse_args()
    if any(not Path(root).is_dir() for root in args.roots):
        parser.error("Every input must be an existing directory")
    odds, times, counts = scan(args.roots)
    print(json.dumps(assess(odds, times, counts), indent=2))


if __name__ == "__main__":
    main()
