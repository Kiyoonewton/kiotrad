"""Mac-only production backup and disposable local restore check; no production restore."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import secrets
import sys
import subprocess

from plan_production_history import ROOT, current_code, production_snapshot, required
from production_backup_io import backup_raw, backup_views, digest, dump_collection, restore_archives, source_metadata
from production_backup_scripts import cleanup_script
import sync


def plan_input(path):
    body = Path(path).read_bytes()
    plan = json.loads(body)
    if plan.get('version') != 'production-history-plan-1.0.0' or plan.get('mode') != 'read_only_production_plan' or plan.get('applied') is not False:
        raise ValueError('Use the completed read-only production plan.')
    if plan.get('blocked_seasons') or not plan.get('provenance', {}).get('inventory_completion') or plan['provenance']['inventory_completion'].get('applied') is not False:
        raise ValueError('Resolve blocked seasons or incomplete inventory before backup preparation.')
    return plan, hashlib.sha256(body).hexdigest()


def checked_strategy(server, plan):
    snapshot = production_snapshot(server)
    code, replay = current_code()
    fingerprint = hashlib.sha256(json.dumps(snapshot, sort_keys=True).encode()).hexdigest()
    if fingerprint != plan['provenance']['production_strategy_sha256'] or snapshot.get('code_hashes') != code or snapshot.get('replay_version') != replay:
        raise ValueError('Production strategy/settings differ from the plan or local code; regenerate the plan after alignment.')
    return snapshot


def backup(args):
    if sys.platform != 'darwin':
        raise RuntimeError('Run the production backup/restore check on the Mac, never on the server.')
    plan, plan_hash = plan_input(args.plan)
    credentials = required(ROOT)
    server = args.ssh or credentials['KIOTRAD_SYNC_SSH']
    sync.guard_local()
    if not sync.ENV.is_file():
        raise ValueError('Existing local environment is required; no environment will be generated or changed.')
    environment_hash = digest(sync.ENV)
    sync.compose('up', '-d', '--wait', 'mongodb')
    sync.verify_destination()
    snapshot = checked_strategy(server, plan)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    folder = ROOT / 'backups/production-pre-import' / stamp
    folder.mkdir(parents=True, exist_ok=False)
    target = 'kiotrad_local_backup_check_' + stamp + '_' + secrets.token_hex(4)
    (folder / 'plan.json').write_text(json.dumps(plan, indent=2) + '\n')
    (folder / 'strategy.json').write_text(json.dumps(snapshot, indent=2) + '\n')
    print('Backup folder: ' + str(folder), flush=True)
    print('Checking source read-only role and collection metadata...', flush=True)
    uri, metadata = source_metadata(server, credentials['KIOTRAD_SYNC_PASSWORD'])
    (folder / 'database-metadata.json').write_text(json.dumps(metadata, indent=2) + '\n')
    archives = []
    database_folder = folder / 'mongodb'
    database_folder.mkdir()
    # Capture documents last so earlier checkpoint exports can resolve references.
    names = sorted((c['name'] for c in metadata['collections']), key=lambda n: (n == 'provider_captures', n))
    for index, name in enumerate(names, 1):
        print(f'MongoDB backup: {index}/{len(names)} | {name}', flush=True)
        path = database_folder / (hashlib.sha256(name.encode()).hexdigest() + '.archive.gz')
        item = dump_collection(server, uri, name, path)
        archives.append({'collection': name, **item})
        (folder / 'archives.json').write_text(json.dumps(archives, indent=2) + '\n')
    print('Restoring and checking archived BSON in disposable local database: ' + target, flush=True)
    restored = restore_archives(sync, archives, target, database_folder)
    (folder / 'restore-check.json').write_text(json.dumps(restored, indent=2) + '\n')
    print('Backing up permanent raw evidence...', flush=True)
    raw_folder = folder / 'raw-evidence'
    raw_folder.mkdir()
    previous = sorted((p for p in (ROOT / 'backups/raw-sync').glob('*') if p.is_dir() and not p.is_symlink()), reverse=True)
    raw = backup_raw(server, raw_folder, previous)
    print('Backing up last-good dashboard/Simulator files...', flush=True)
    views = backup_views(server, folder)
    if production_snapshot(server) != snapshot:
        raise RuntimeError('Production settings changed during backup; retain these files but rerun before import.')
    if digest(sync.ENV) != environment_hash:
        raise RuntimeError('Local environment changed during backup; verify the active snapshot before continuing.')
    # Keep every backup byte; only the fresh disposable validation database is removed.
    cleanup = 'retained_by_request' if args.keep_restore_check else 'removed'
    if not args.keep_restore_check:
        sync.guard_local()
        sync.verify_destination()
        try:
            sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--file', '/dev/stdin',
                         input=cleanup_script(target), stdout=subprocess.PIPE)
        except RuntimeError:
            cleanup = 'cleanup_pending'
    result = {'version': 'production-pre-import-backup-1.0.0', 'complete': True,
              'production_writes': False, 'production_restore_available': False, 'import_authorized': False,
              'plan_sha256': plan_hash, 'backup_created_at': datetime.now(timezone.utc).isoformat(),
              'production_database': 'kiotrad', 'source_metadata_sha256': digest(folder / 'database-metadata.json'),
              'strategy_sha256': digest(folder / 'strategy.json'), 'archives': archives,
              'database_restore_verified': True, 'restore_check': restored,
              'restore_check_sha256': digest(folder / 'restore-check.json'), 'disposable_database_status': cleanup,
              'raw_evidence': raw, 'last_good_views': views, 'active_local_environment_unchanged': True,
              'limits': ['This is a rolling non-transactional backup; collection contents are not one globally consistent instant.',
                         'Restore counts match native archive restore summaries, not changing live-source estimated counts.',
                         'Secondary/TTL indexes are preserved in archive metadata and source metadata but not activated during the data restore test.',
                         'Last-good views are expiring rebuildable caches, not durable financial ledgers; Redis is not backed up.',
                         'MongoDB users, server configuration, env secrets and deployment artifacts are outside this data/settings backup.',
                         'No automatic production restore/import is provided. Review the plan and consistency/rollback requirements before any later write.']}
    # Completion marker comes last, after all archive/raw/view checks.
    (folder / 'report.json').write_text(json.dumps(result, indent=2) + '\n')
    return folder, result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan', required=True)
    parser.add_argument('--ssh')
    parser.add_argument('--keep-restore-check', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    try:
        folder, result = backup(args)
    except (OSError, ValueError, KeyError, TypeError, RuntimeError) as error:
        parser.exit(1, 'Production backup stopped: ' + str(error) + '\nNo production data changed. Incomplete local backup/check data is retained for inspection.\n')
    print('Backup complete:', folder)
    print('MongoDB archives:', len(result['archives']))
    print('Native isolated restore: PASS')
    print('Original raw files:', result['raw_evidence']['files'])
    print('Last-good view files:', len(result['last_good_views']))
    print('Disposable restore database:', result['disposable_database_status'])
    print('Production and the active local dashboard were not changed. Production import remains a separate reviewed step.')
