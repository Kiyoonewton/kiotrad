"""Stage exact legacy responses for offline validation/import; never touches MongoDB."""
import argparse
import hashlib
import json
import re
import shutil
from collections import Counter
from pathlib import Path

ROUTE = re.compile(r"vfc_stats_round_odds2/vf:season:(\d+)/(\d+)\Z")
LEAGUES = {"vf:tournament:31867": "virtual-football", "vf:tournament:34616": "virtual-bundesliga", "vf:tournament:14149": "virtual-euro"}


def package(source, destination, seasons=None):
    source, destination = Path(source).resolve(), Path(destination).resolve()
    if not source.is_dir() or destination.exists() or source == destination or source in destination.parents:
        raise ValueError("Use an existing source directory and a new destination outside it.")
    (destination / "files").mkdir(parents=True)
    manifest, coverage, seen = [], Counter(), set()
    counts = Counter()
    for path in sorted(source.rglob("*.json")):
        if path.is_symlink() or not path.is_file():
            continue
        if path.stat().st_size > 20 * 1024 * 1024:
            counts["oversized"] += 1
            continue
        try:
            body = path.read_bytes()
            payload = json.loads(body)
            route = ROUTE.fullmatch(payload.get("queryUrl", "")) if isinstance(payload, dict) else None
            if not route:
                counts["not_odds"] += 1
                continue
            season, day = route.group(1), int(route.group(2))
            if seasons and season not in seasons:
                continue
            if not 1 <= day <= 30:
                raise ValueError("invalid round")
            events = payload["doc"][0]["data"]["odds"]
            league = LEAGUES.get(events[0]["tournament_id"]) if events else None
            if not league:
                counts["unknown_or_empty"] += 1
                continue
            digest = hashlib.sha256(body).hexdigest()
            if digest in seen:
                counts["identical_duplicates"] += 1
                continue
            seen.add(digest)
            # Preserve exact bytes and original basename; hash avoids filename collisions.
            name = digest + "-" + path.name
            shutil.copyfile(path, destination / "files" / name)
            manifest.append({"file": name, "source_filename": path.name, "sha256": digest,
                             "competition": league, "season_id": season, "matchday": day,
                             "original_received_at": None})
            coverage[(league, season)] += 1
            counts["staged"] += 1
        except (ValueError, KeyError, TypeError, IndexError):
            counts["malformed"] += 1
    (destination / "manifest.json").write_text(json.dumps({"files": manifest, "counts": dict(counts)}, indent=2) + "\n")
    return counts, coverage


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source")
    parser.add_argument("destination")
    parser.add_argument("--seasons", help="Optional comma-separated season IDs")
    args = parser.parse_args()
    counts, coverage = package(args.source, args.destination, set(args.seasons.split(",")) if args.seasons else None)
    for (league, season), files in sorted(coverage.items()):
        print(f"{league:<20} {season} | source files {files}")
    print(json.dumps(dict(counts)))
    print("Staged only. Server dry-run must validate every fixture and price before import.")
