"""Audit the active local snapshot's raw files without syncing or rebuilding views."""
import csv
from datetime import datetime, timezone
import json
from pathlib import Path
import re


def audit(sync):
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
    sync.compose('build', 'api')
    sync.compose('run', '--rm', '--no-deps', '-T',
                 '-e', 'MONGODB_DATABASE=' + database,
                 '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                 '-v', str(source.resolve()) + ':/raw-evidence:ro',
                 '-v', str(destination.resolve()) + ':/audit', 'api',
                 'php', '-d', 'memory_limit=512M', 'artisan',
                 'provider:audit-raw-documents', '/raw-evidence', '--report=/audit/report.jsonl')
    summary = None
    fields = ['type', 'phase', 'season_id', 'reason', 'file', 'compressed_sha256', 'indexed_observed_at']
    with (destination / 'report.jsonl').open() as incoming, (destination / 'rejections.csv').open('x') as output:
        table = csv.DictWriter(output, fieldnames=fields, extrasaction='ignore')
        table.writeheader()
        for line in incoming:
            row = json.loads(line)
            if row.get('type') in ('rejection', 'not_validated'):
                table.writerow(row)
            elif row.get('type') == 'summary':
                summary = row
    if summary is None or summary.get('complete') is not True or summary.get('database') != database:
        raise RuntimeError('Audit report is incomplete; preserve it for diagnostics.')
    print('Reasons:', json.dumps(summary.get('reasons', {})), flush=True)
    print('Review table: ' + str(destination / 'rejections.csv'), flush=True)
    print('No database, dashboard, strategy or raw source changes.', flush=True)
    return destination


if __name__ == '__main__':
    import sync
    try:
        audit(sync)
    except (OSError, RuntimeError, ValueError) as error:
        raise SystemExit('Raw document audit stopped: ' + str(error)) from None
