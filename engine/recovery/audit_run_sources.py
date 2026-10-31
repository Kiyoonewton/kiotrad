"""Audit newly completed runs against selected original legacy package files."""
import argparse
import hashlib
import json
import sys
from pathlib import Path

from audit_history_comparison import table
from legacy_source_evidence import SourcePackage
from run_source_audit import audit_sources


def write_audit(comparison, package, destination, progress=None):
    body = Path(comparison).read_bytes()
    result = audit_sources(json.loads(body), SourcePackage(package, progress))
    result['comparison_sha256'] = hashlib.sha256(body).hexdigest()
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(result, indent=2) + '\n')
    for name in ('runs', 'champions', 'quotes', 'issues'):
        table(destination / (name + '.csv'), result[name])
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('comparison')
    parser.add_argument('--package', required=True)
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    try:
        result = write_audit(args.comparison, args.package, args.destination,
            lambda league, season, files: print(f'Raw evidence: {league} | season {season} | {files} files', file=sys.stderr, flush=True))
    except (OSError, ValueError, KeyError, TypeError, IndexError, AttributeError) as error:
        parser.exit(1, 'Run source audit stopped: ' + str(error) + '\n')
    print('Added-run source audit:', 'PASS' if result['passed'] else 'FAIL')
    for row in result['runs']:
        print(row['run_id'], '|', row['team'], '| first win:', row['first_win_day'], '|', row['first_results'], '|', 'PASS' if row['passed'] else 'FAIL')
    print('Verified raw files:', len(result['verified_source_sha256s']))
    print('Issues:', len(result['issues']))
    print('Tables:', str(Path(args.destination)))
    print('Source corroboration only; estimated dates and market availability remain unverified.')
    if not result['passed']:
        parser.exit(1)
