"""Replay explicitly registered, hash-pinned legacy packages into local staging."""
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile


DATABASE = re.compile(r'kiotrad_local_[0-9TZ]+')
HASH = re.compile(r'[a-f0-9]{64}')


def registry_path(root):
    return Path(root) / 'recovery/history-overlays.json'


def describe(root, folder, pinned=None):
    path = Path(folder).expanduser()
    if not path.is_absolute():
        path = Path(root) / path
    if path.is_symlink() or not path.is_dir():
        raise RuntimeError('Registered history package is missing or is a symlink: ' + str(path))
    path = path.resolve()
    if (Path(root) / 'backups').resolve() in path.parents or path == (Path(root) / 'backups').resolve():
        raise RuntimeError('History packages must live outside pruned backups folders.')
    for name in ['manifest.json', 'plan.json']:
        if (path / name).is_symlink() or not (path / name).is_file():
            raise RuntimeError('History package needs regular manifest.json and plan.json files.')
    body = (path / 'manifest.json').read_bytes()
    digest = hashlib.sha256(body).hexdigest()
    try:
        manifest = json.loads(body)
    except (ValueError, UnicodeError):
        raise RuntimeError('Invalid JSON history package manifest.') from None
    if pinned is not None and digest != pinned:
        raise RuntimeError('Registered history manifest changed; preserve it and register a new package directory.')
    if not isinstance(manifest, dict) or manifest.get('package_version') != 'legacy-history-package-1.0.0' or not manifest.get('files'):
        raise RuntimeError('Invalid or empty legacy history package.')
    if hashlib.sha256((path / 'plan.json').read_bytes()).hexdigest() != manifest.get('plan_sha256'):
        raise RuntimeError('History package plan checksum differs.')
    return {'path': str(path), 'manifest_sha256': digest}


def packages(root, requested=()):
    registry = registry_path(root)
    known = {}
    if registry.is_symlink():
        raise RuntimeError('History registry may not be a symlink.')
    if registry.exists():
        try:
            data = json.loads(registry.read_text())
        except (ValueError, UnicodeError):
            raise RuntimeError('Invalid JSON history overlay registry.') from None
        if not isinstance(data, dict) or data.get('version') != 1 or not isinstance(data.get('packages'), list):
            raise RuntimeError('Invalid history overlay registry.')
        for record in data['packages']:
            if not isinstance(record, dict) or not HASH.fullmatch(str(record.get('manifest_sha256', ''))) or not isinstance(record.get('path'), str):
                raise RuntimeError('Invalid registered history package identity.')
            known[record['manifest_sha256']] = record
    # An identical manifest at an explicitly supplied path can relocate a package.
    for folder in requested or ():
        record = describe(root, folder)
        known[record['manifest_sha256']] = record
    return [describe(root, row['path'], row['manifest_sha256']) for row in known.values()]


def save(root, records):
    registry = registry_path(root)
    registry.parent.mkdir(parents=True, exist_ok=True)
    if registry.parent.is_symlink() or registry.is_symlink():
        raise RuntimeError('History registry directory may not be a symlink.')
    temporary = None
    try:
        with tempfile.NamedTemporaryFile('w', dir=registry.parent, delete=False) as output:
            temporary = Path(output.name)
            json.dump({'version': 1, 'packages': records}, output, indent=2)
            output.flush()
            os.fsync(output.fileno())
        temporary.replace(registry)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def replay(sync, database, records):
    if not records:
        print('History overlays: none registered.', flush=True)
        return
    active = next((line.split('=', 1)[1] for line in sync.ENV.read_text().splitlines()
                   if line.startswith('LOCAL_DATABASE=')), '')
    if not DATABASE.fullmatch(database) or database == active:
        raise RuntimeError('History overlays require a separate timestamped local staging database.')
    sync.verify_destination()
    archive = sync.ROOT / 'recovery/local-history-evidence'
    if archive.is_symlink():
        raise RuntimeError('Persistent history evidence directory may not be a symlink.')
    archive.mkdir(parents=True, exist_ok=True)
    sync.compose('up', '-d', '--wait', 'redis', 'redis-locks')
    for record in records:
        checked = describe(sync.ROOT, record['path'], record['manifest_sha256'])
        print('Replaying retained history package into staging: ' + checked['path'], flush=True)
        # Importer hash-checks every source, archives originals, then applies only
        # compatible missing evidence. Completed import-ledger rows are idempotent.
        sync.compose('run', '--rm', '--no-deps', '-T',
                     '-e', 'MONGODB_DATABASE=' + database,
                     '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                     '-v', checked['path'] + ':/legacy-history:ro',
                     '-v', str(archive.resolve()) + ':/history-evidence', 'api',
                     'php', 'artisan', 'provider:import-legacy-history', '/legacy-history',
                     '--apply', '--archive-root=/history-evidence')
    print('Verifying retained fixture, price and provenance identities...', flush=True)
    reports = sync.ROOT / 'recovery/history-verification'
    reports.mkdir(parents=True, exist_ok=True)
    report = reports / (database + '.jsonl')
    try:
        with report.open('wb') as output:
            sync.compose('run', '--rm', '--no-deps', '-T',
                         '-e', 'MONGODB_DATABASE=' + database,
                         '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                         'api', 'php', 'artisan', 'provider:verify-legacy-history', stdout=output)
    finally:
        print(report.read_text(), end='', flush=True)
        print('History integrity report: ' + str(report), flush=True)
    # Register only after all imports succeed. Keep registration even if later
    # warming/activation fails, so the next normal sync retries the same evidence.
    save(sync.ROOT, records)
    print('History overlays preserved; future raw syncs will replay these packages.', flush=True)
