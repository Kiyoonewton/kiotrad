"""Audit the active local snapshot's raw files without syncing or rebuilding views."""
import csv
from datetime import datetime, timezone
import json
from pathlib import Path
import re


def audit(sync, previous_report=None):
    sync.guard_local()
    sync.verify_destination()
    database = next((line.split('=', 1)[1] for line in sync.ENV.read_text().splitlines()
                     if line.startswith('LOCAL_DATABASE=')), '')
    if not re.fullmatch(r'kiotrad_local_[0-9TZ]+', database):
        raise RuntimeError('Use the completed timestamped local mirror.')
    source = sync.ROOT / 'backups/raw-sync' / database.removeprefix('kiotrad_local_')
    if source.is_symlink() or not (source / 'raw-files.json').is_file():
        raise RuntimeError('Retained raw snapshot for the active database was not found.')
    destination = sync.ROOT / 'recovery/raw-document-audit' / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    destination.mkdir(parents=True, exist_ok=False)
    print('Database (read only): ' + database, flush=True)
    print('Report folder: ' + str(destination), flush=True)
    extra_mounts = []
    extra_arguments = []
    if previous_report is not None:
        previous = Path(previous_report).expanduser()
        if not previous.is_absolute():
            previous = sync.ROOT / previous
        if previous.is_symlink() or not previous.is_file():
            raise RuntimeError('Previous audit report is missing or is a symlink.')
        previous = previous.resolve()
        extra_mounts = ['-v', str(previous.parent) + ':/previous-audit:ro']
        extra_arguments = ['--rejections-from=/previous-audit/' + previous.name]
    sync.compose('build', 'api')
    sync.compose('run', '--rm', '--no-deps', '-T',
                 '-e', 'MONGODB_DATABASE=' + database,
                 '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                 '-v', str(source.resolve()) + ':/raw-evidence:ro',
                 '-v', str(destination.resolve()) + ':/audit', *extra_mounts, 'api',
                 'php', '-d', 'memory_limit=512M', 'artisan',
                 'provider:audit-raw-documents', '/raw-evidence', '--report=/audit/report.jsonl', *extra_arguments)
    summary = None
    coverage = dict.fromkeys(['source_prices', 'matching_existing', 'different_existing', 'missing'], 0)
    fields = ['type', 'phase', 'season_id', 'reason', 'file', 'compressed_sha256', 'indexed_observed_at']
    with (destination / 'report.jsonl').open() as incoming, (destination / 'rejections.csv').open('x') as output, \
            (destination / 'join-context.csv').open('x') as joined:
        contexts = csv.DictWriter(joined, fieldnames=['file', 'competition', 'season_id', 'matchday',
            'fixture_capture_id', 'fixture_capture_observed_at', 'fixture_context_before_odds',
            'joined_fixtures', 'source_price_coverage', 'raw_legacy_acceptance_limited'], extrasaction='ignore')
        contexts.writeheader()
        table = csv.DictWriter(output, fieldnames=fields, extrasaction='ignore')
        table.writeheader()
        for line in incoming:
            row = json.loads(line)
            if row.get('type') in ('rejection', 'not_validated'):
                table.writerow(row)
            elif row.get('type') == 'validated_odds':
                contexts.writerow({**row, 'source_price_coverage': json.dumps(row['source_price_coverage'])})
                for key in coverage:
                    coverage[key] += row['source_price_coverage'][key]
            elif row.get('type') == 'summary':
                summary = row
    if summary is None or summary.get('complete') is not True or summary.get('database') != database:
        raise RuntimeError('Audit report is incomplete; preserve it for diagnostics.')
    print('Reasons:', json.dumps(summary.get('reasons', {})), flush=True)
    if previous_report is not None:
        print('Quote coverage (response occurrences, not unique matches): ' + json.dumps(coverage), flush=True)
        print('Joined context table: ' + str(destination / 'join-context.csv'), flush=True)
    print('Review table: ' + str(destination / 'rejections.csv'), flush=True)
    print('No database, dashboard, strategy or raw source changes.', flush=True)
    return destination


if __name__ == '__main__':
    import argparse
    import sync
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--rejections-from', help='Revalidate only rejected files from a completed audit of this same database')
    args = parser.parse_args()
    try:
        audit(sync, args.rejections_from)
    except (OSError, RuntimeError, ValueError) as error:
        raise SystemExit('Raw document audit stopped: ' + str(error)) from None
