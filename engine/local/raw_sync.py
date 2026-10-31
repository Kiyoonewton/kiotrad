"""Raw-only source transfer and disposable local reconstruction."""
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile
from datetime import datetime, timezone

from raw_manifest import manifest_script, parse_manifest, tar_input, verify_files
from raw_reuse import reuse_files


def extract_evidence(archive, target):
    """Extract regular raw evidence only; reject traversal, links and extra files."""
    count = 0
    with tarfile.open(archive, 'r|gz') as source:
        for member in source:
            path = Path(member.name)
            if path.is_absolute() or '..' in path.parts or path.parts[:2] != ('provider-evidence', 'raw'):
                raise RuntimeError('Unsafe raw archive path.')
            if member.isdir():
                continue
            if not member.isfile() or not member.name.endswith('.json.gz'):
                raise RuntimeError('Unexpected raw archive entry.')
            destination = target / path
            destination.parent.mkdir(parents=True, exist_ok=True)
            with source.extractfile(member) as incoming, destination.open('wb') as output:
                import shutil
                shutil.copyfileobj(incoming, output)
            with gzip.open(destination, 'rt') as stream:
                record = json.load(stream)
            import base64
            body = base64.b64decode(record['body_base64'], validate=True)
            if hashlib.sha256(body).hexdigest() != record['body_sha256']:
                raise RuntimeError('Raw evidence checksum mismatch.')
            count += 1
    if count == 0:
        raise RuntimeError('No raw evidence received; existing local data remains active.')
    return count


def strategy_script():
    return b'''<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo json_encode(app(App\\Domain\\Strategies\\SimulationDefinitionSnapshot::class)->current(), JSON_THROW_ON_ERROR);
'''


def perform(sync, server, full_rebuild=False, history_packages=()):
    from history_overlay import packages, replay
    overlays = packages(sync.ROOT, history_packages)
    os.umask(0o077)
    if not sync.ENV.exists():
        sync.write_environment('kiotrad_local')
    sync.compose('up', '-d', '--wait', 'mongodb')
    sync.verify_destination()
    started = datetime.now(timezone.utc).isoformat()
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    database = 'kiotrad_local_' + stamp
    folder = sync.ROOT / 'backups/raw-sync' / stamp
    folder.mkdir(parents=True, exist_ok=False)
    print('Downloading strategy definitions and settings...', flush=True)
    snapshot = sync.run(sync.ssh_command(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'php']),
                        phase='strategy snapshot', input=strategy_script(), stdout=subprocess.PIPE).stdout
    settings = json.loads(snapshot)
    if not isinstance(settings.get('capital_minor'), int) or settings['capital_minor'] < 10000:
        raise RuntimeError('Invalid strategy settings.')
    (folder / 'strategy.json').write_bytes(snapshot)
    definitions = sync.ROOT / 'backups/strategy-definitions'
    definitions.mkdir(parents=True, exist_ok=True)
    (definitions / (hashlib.sha256(snapshot).hexdigest() + '.json')).write_bytes(snapshot)
    print('Preparing a fixed raw-file snapshot (latest 60 seconds deferred)...', flush=True)
    manifest = parse_manifest(sync.run(sync.ssh_command(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'php']),
                                      phase='raw file manifest', input=manifest_script(), stdout=subprocess.PIPE).stdout)
    (folder / 'raw-files.json').write_text(json.dumps(manifest, indent=2))
    delta, reused = reuse_files(manifest, folder)
    total = sum(entry['bytes'] for entry in delta['files'])
    print(f"Reusing {reused} verified local raw files; downloading {len(delta['files'])} new or changed files ({total / 1048576:.1f} MiB)...", flush=True)
    archive = folder / 'raw.tar.gz'
    downloaded = 0
    if delta['files']:
        with archive.open('wb') as output:
            sync.run(sync.ssh_command(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'tar',
                     '--null', '--no-recursion', '-czf', '-', '-C', '/app/storage/app/private', '-T', '-']),
                     phase='raw evidence download', input=tar_input(delta), stdout=output)
        downloaded = extract_evidence(archive, folder)
    count = reused + downloaded
    verify_files(manifest, folder, count)
    print(f'Validated {count} raw responses. Rebuilding local derived views...', flush=True)
    from raw_seed import seed_snapshot
    sync.compose('build', 'api')
    parser_version = sync.compose('run', '--rm', '--no-deps', '-T', 'api', 'php', '-r',
        'require "/app/vendor/autoload.php"; $app = require "/app/bootstrap/app.php"; '
        '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
        'echo app(App\\Infrastructure\\Provider\\RawRebuildIndex::class)->version();',
        stdout=subprocess.PIPE).stdout.decode().strip()
    if len(parser_version) != 64 or any(c not in '0123456789abcdef' for c in parser_version):
        raise RuntimeError('Cannot verify current raw parser version.')
    seed_snapshot(sync, database, folder, full_rebuild, parser_version=parser_version)
    sync.compose('run', '--rm', '--no-deps', '-e', 'MONGODB_DATABASE=' + database,
                 '-e', 'MONGODB_URI=mongodb://mongodb:27017/' + database,
                 '-v', str(folder.resolve()) + ':/raw-evidence:ro', 'api',
                 'php', 'artisan', 'provider:rebuild-raw', '/raw-evidence')
    # Blockage classifications are permanent operator evidence, not simulations.
    password = sync.getpass('Production kiotrad_dev password (read-only, blockage records): ')
    if not password:
        raise RuntimeError('Empty password rejected.')
    uri = 'mongodb://kiotrad_dev:' + sync.quote(password, safe='') + '@127.0.0.1:27017/kiotrad?authSource=kiotrad'
    available = sync.source_collections(server, uri)
    if 'market_blockages' in available:
        with (folder / 'market_blockages.archive.gz').open('wb') as output:
            sync.run(sync.source_command(server, 'market_blockages'), input=json.dumps({'uri': uri}).encode(), stdout=output)
        with (folder / 'market_blockages.archive.gz').open('rb') as source:
            sync.compose('exec', '-T', 'mongodb', 'mongorestore', '--archive', '--gzip',
                         '--nsFrom=kiotrad.*', '--nsTo=' + database + '.*', '--drop', '--stopOnError', stdin=source)
    from production_history_mirror import recover
    recovered = recover(sync, database, folder, overlays)
    overlays = packages(sync.ROOT, [*history_packages, *recovered])
    replay(sync, database, overlays)
    print('Validating staging metadata before preparing views...', flush=True)
    sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--file', '/dev/stdin',
                 phase='validate staging snapshot', input=sync.validation_script(database, started).encode())
    from prewarm import prepare
    prepare(sync, database, settings['capital_minor'])
    previous = sync.ENV.read_bytes()
    previous_database = next((line.split('=', 1)[1] for line in previous.decode().splitlines()
                              if line.startswith('LOCAL_DATABASE=')), None)
    try:
        # Settings are source evidence; local simulations must use this snapshot's capital.
        content = sync.ENV.read_text()
        lines = [s for s in content.splitlines() if not s.startswith('SIMULATION_CAPITAL_NAIRA=')]
        sync.ENV.write_text('\n'.join(lines) + '\nSIMULATION_CAPITAL_NAIRA=' + str(settings['capital_minor'] / 100) + '\n')
        sync.activate_snapshot(database, started)
    except BaseException:
        sync.ENV.write_bytes(previous)
        sync.compose('up', '-d', '--no-deps', '--force-recreate', 'api', 'web', 'dashboard-worker')
        raise
    (folder / 'manifest.json').write_text(json.dumps({'database': database, 'raw_files': count,
        'started_at': started, 'mode': 'raw_evidence_rebuild'}, indent=2))
    # The downloaded tar is only transport; validated extracted files are the retained evidence.
    archive.unlink(missing_ok=True)
    try:
        from snapshot_retention import prune
        prune(sync, database, previous_database)
    except (RuntimeError, OSError) as error:
        print('New snapshot is active; older-copy cleanup needs attention: ' + str(error))
    print('Raw evidence and strategy snapshot retained; local MongoDB views are rebuildable.')
