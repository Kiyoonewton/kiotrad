"""Package original legacy history plus a source-aware, offline import plan.

No database access or import. New historical fixtures still require a dedicated
parser with explicit estimated/unknown timing; the existing odds importer must
not be used to invent fixtures or timestamps.
"""
import argparse
import hashlib
import json
import shutil
import sys
from collections import Counter
from pathlib import Path

from source_coverage import Coverage, compare_sources
from timing_audit import LEAGUES, ROUTE, scan


def price_comparison(legacy, current, key):
    old = legacy.rows[key]['odds']
    existing = current.rows.get(key, {}).get('odds', {})
    missing, matching, differing, result_conflicts = [], [], [], []
    for identity, evidence in sorted(old.items()):
        present = existing.get(identity, {})
        item = {'matchday': identity[0], 'provider_match_id': identity[1]}
        prices, other = evidence['prices'], present.get('prices', set())
        if prices:
            if not other:
                missing.append(item)
            elif prices & other:
                matching.append(item)
            else:
                differing.append(item)
        results, other_results = evidence['results'], present.get('results', set())
        if len(results) > 1 or (results and other_results and results != other_results):
            result_conflicts.append(item)
    return {'missing_prices': missing, 'matching_prices': matching,
            'different_recorded_prices': differing, 'result_conflicts': result_conflicts}


def package(source, snapshot, destination):
    source, snapshot, destination = map(lambda p: Path(p).resolve(), (source, snapshot, destination))
    if not source.is_dir() or not snapshot.is_dir() or destination.exists():
        raise ValueError('Use two existing input directories and a new destination.')
    paths = (source, snapshot, destination)
    if any(a == b or a in b.parents or b in a.parents
           for i, a in enumerate(paths) for b in paths[i + 1:]):
        raise ValueError('Inputs and destination must be separate, non-nested directories.')
    current = Coverage()
    _, times, source_counts = scan([snapshot], current)
    legacy = Coverage()
    files, seen, counts = [], set(), Counter()
    destination.mkdir(parents=True)
    (destination / 'files').mkdir()
    # Completion marker (manifest) is written last. Never consume a partial package.
    for number, path in enumerate(source.rglob('*.json'), 1):
        if number % 1000 == 0:
            print(f'Packaging legacy files: {number} inspected...', file=sys.stderr, flush=True)
        if path.is_symlink() or not path.is_file():
            continue
        counts['inspected'] += 1
        if path.stat().st_size > 20 * 1024 * 1024:
            counts['oversized'] += 1
            continue
        try:
            body = path.read_bytes()
            payload = json.loads(body)
            route = ROUTE.fullmatch(str(payload.get('queryUrl', ''))) if isinstance(payload, dict) else None
            if not route:
                counts['not_odds'] += 1
                continue
            season, day = route.group(1), int(route.group(2))
            events = payload['doc'][0]['data']['odds']
            leagues = {LEAGUES.get(str(e['tournament_id']).split(':')[-1]) for e in events}
            if not 1 <= day <= 30 or len(leagues) != 1 or None in leagues:
                counts['unknown_empty_or_mixed_league'] += 1
                continue
            league = next(iter(leagues))
            legacy.collect(payload, route)
            digest = hashlib.sha256(body).hexdigest()
            if digest in seen:
                counts['identical_duplicates'] += 1
                continue
            seen.add(digest)
            name = digest + '.json'
            shutil.copyfile(path, destination / 'files' / name)
            files.append({'file': name, 'source_filename': path.name, 'sha256': digest,
                          'competition': league, 'season_id': season, 'matchday': day,
                          'original_received_at': None})
            counts['staged'] += 1
        except (ValueError, KeyError, IndexError, TypeError, AttributeError):
            counts['malformed'] += 1
    seasons = []
    for row in compare_sources(legacy, current):
        key = row['competition'], row['season_id']
        if key not in legacy.rows:
            continue
        comparison = price_comparison(legacy, current, key)
        verified = times.get(key, {})
        row['kiotrad_verified_days'] = len([v for v in verified.values() if len(v) == 1])
        row['price_comparison'] = comparison
        row['next_step'] = ('new_history_parser_required' if row['source_presence'] == 'legacy_only'
                            else 'existing_fixture_and_price_validation_required')
        row['financial_replay_ready'] = False
        seasons.append(row)
    report = {'version': 'legacy-history-package-1.0.0', 'applied': False,
              'snapshot_counts': dict(source_counts), 'package_counts': dict(counts),
              'seasons': seasons,
              'limits': ['No records imported, no synthetic timestamps or scores created.',
                         'Price differences may be legitimate market movements; never overwrite automatically.',
                         'Snapshot comparison is not a live production database check.',
                         'Partial seasons are retained; interruption causes remain unconfirmed.',
                         'Complete odds/result coverage is not full fixture or continuity validation.',
                         'Provider fixture results and old odds results must be reconciled before apply.']}
    (destination / 'plan.json').write_text(json.dumps(report, indent=2) + '\n')
    (destination / 'manifest.json').write_text(json.dumps({
        'files': files, 'counts': dict(counts), 'package_version': report['version'],
        'plan_sha256': hashlib.sha256((destination / 'plan.json').read_bytes()).hexdigest(),
    }, indent=2) + '\n')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('legacy')
    parser.add_argument('kiotrad')
    parser.add_argument('destination')
    args = parser.parse_args()
    report = package(args.legacy, args.kiotrad, args.destination)
    print('Package:', args.destination)
    print('Files:', report['package_counts'])
    for league in sorted(set(r['competition'] for r in report['seasons'])):
        rows = [r for r in report['seasons'] if r['competition'] == league]
        print(league, dict(Counter(r['source_presence'] for r in rows)))
    print('Staged only. Review plan.json; no database or balance changes.')


if __name__ == '__main__':
    main()
