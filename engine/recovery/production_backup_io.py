"""Backup bytes and native archive restore checks; production operations only read."""
import base64
import gzip
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
from urllib.parse import quote

from plan_production_history import ssh
from production_backup_scripts import fresh_script, metadata_script, target_guard, validation_script, VIEWS_SCRIPT
from raw_manifest import manifest_script, parse_manifest, tar_input, verify_files
from raw_sync import extract_evidence


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def remote(server, args, payload=None, output=subprocess.PIPE):
    result = subprocess.run(ssh(server, args), input=payload, stdout=output, stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError('Production backup read failed; credentials hidden.')
    return result.stdout


def source_metadata(server, password):
    uri = 'mongodb://kiotrad_dev:' + quote(password, safe='') + '@127.0.0.1:27017/kiotrad?authSource=kiotrad'
    body = remote(server, ['docker', 'exec', '-i', 'kiotrad-mongodb-1', 'mongosh', '--quiet', '--nodb', '--file', '/dev/stdin'], metadata_script(uri))
    data = json.loads(body)
    if data.get('database') != 'kiotrad' or data.get('read_only_role_verified') is not True or not data.get('collections'):
        raise ValueError('Production backup requires the verified read-only database role.')
    names = [r['name'] for r in data['collections']]
    if len(set(names)) != len(names) or any(not re.fullmatch(r'[A-Za-z0-9_.-]+', n) or n.startswith('system.') for n in names):
        raise ValueError('Unsupported source collection identity.')
    return uri, data


def dump_collection(server, uri, collection, path):
    if not re.fullmatch(r'[A-Za-z0-9_.-]+', collection) or collection.startswith('system.'):
        raise ValueError('Invalid collection name.')
    with Path(path).open('xb') as output:
        remote(server, ['docker', 'exec', '-i', 'kiotrad-mongodb-1', 'mongodump', '--config=/dev/stdin',
                        '--db=kiotrad', '--collection=' + collection, '--archive', '--gzip'], json.dumps({'uri': uri}).encode(), output)
    if Path(path).stat().st_size == 0:
        raise ValueError('Empty MongoDB backup archive.')
    return {'file': Path(path).name, 'bytes': Path(path).stat().st_size, 'sha256': digest(path)}


def restore_count(stderr):
    matches = re.findall(r'(\d+) document\(s\) restored successfully\. (\d+) document\(s\) failed to restore\.', stderr.decode(errors='replace'))
    if len(matches) != 1 or int(matches[0][1]) != 0:
        raise ValueError('Native restore summary is missing or reports failures.')
    return int(matches[0][0])


def restore_archives(sync, archives, target, root):
    target_guard(target)
    sync.guard_local()
    sync.verify_destination()
    sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--file', '/dev/stdin', input=fresh_script(target), stdout=subprocess.PIPE)
    expected = {}
    for item in archives:
        path = root / item['file']
        if path.is_symlink() or digest(path) != item['sha256']:
            raise ValueError('Backup archive checksum changed before restore test.')
        print('Restore check: ' + item['collection'], flush=True)
        with path.open('rb') as archive:
            # Do not restore TTL indexes during a data comparison: they can expire
            # archived cache documents while the check is still running.
            result = sync.compose('exec', '-T', 'mongodb', 'mongorestore', '--archive', '--gzip', '--nsInclude=kiotrad.*',
                                  '--nsFrom=kiotrad.*', '--nsTo=' + target + '.*', '--noIndexRestore', '--stopOnError',
                                  stdin=archive, stdout=subprocess.PIPE)
        expected[item['collection']] = restore_count(result.stderr)
    result = sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--file', '/dev/stdin',
                          input=validation_script(target, expected), stdout=subprocess.PIPE)
    checked = json.loads(result.stdout)
    if checked.get('database') != target or checked.get('verified') is not True or checked.get('counts') != expected:
        raise ValueError('Isolated native restore verification failed.')
    return checked


def backup_raw(server, destination, previous_roots):
    # A backup includes newly finished files too. A racing/incomplete write must
    # fail the subsequent checksum/envelope checks, never publish completion.
    manifest = parse_manifest(remote(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'php'], manifest_script(safety_lag=0)))
    (destination / 'raw-files.json').write_text(json.dumps(manifest, indent=2) + '\n')
    pending, reused = [], 0
    for number, entry in enumerate(manifest['files'], 1):
        target = destination / entry['path']
        found = next((r / entry['path'] for r in previous_roots if (r / entry['path']).is_file()
                      and not (r / entry['path']).is_symlink() and (r / entry['path']).stat().st_size == entry['bytes']
                      and digest(r / entry['path']) == entry['sha256']), None)
        if found is None:
            pending.append(entry)
        else:
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(found, target)  # Independent backup bytes, never a hard link.
            reused += 1
        if number % 1000 == 0:
            print(f'Raw backup verified/copied: {number}/{len(manifest["files"])}', flush=True)
    count = 0
    if pending:
        path = destination / 'raw-download.tar.gz'
        print('Downloading new raw backup files: ' + str(len(pending)), flush=True)
        with path.open('xb') as output:
            remote(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'tar', '--null', '--no-recursion', '-czf', '-',
                            '-C', '/app/storage/app/private', '-T', '-'], tar_input({'files': pending}), output)
        count = extract_evidence(path, destination)
    print('Checking every copied/downloaded raw file hash...', flush=True)
    verify_files(manifest, destination, reused + count)
    for number, entry in enumerate(manifest['files'], 1):
        with gzip.open(destination / entry['path'], 'rt') as stream:
            envelope = json.load(stream)
        body = base64.b64decode(envelope['body_base64'], validate=True)
        if hashlib.sha256(body).hexdigest() != envelope['body_sha256']:
            raise ValueError('Raw backup envelope integrity failed.')
        if number % 1000 == 0:
            print(f'Raw envelope integrity: {number}/{len(manifest["files"])}', flush=True)
    return {'files': len(manifest['files']), 'copied_independent_files': reused, 'downloaded_files': count,
            'manifest_sha256': digest(destination / 'raw-files.json'), 'cutoff_unix': manifest['cutoff_unix']}


def backup_views(server, destination):
    body = remote(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'php', '-d', 'memory_limit=512M'], VIEWS_SCRIPT)
    folder = destination / 'dashboard-views'
    folder.mkdir()
    files, complete = [], None
    for line in body.splitlines():
        row = json.loads(line)
        if row.get('type') == 'complete':
            if complete is not None or row.get('files') != len(files):
                raise ValueError('Incomplete last-good view backup.')
            complete = row
            continue
        if complete is not None or row.get('type') != 'view' or not re.fullmatch(r'[a-f0-9]{64}\.json', row.get('name', '')):
            raise ValueError('Invalid last-good view backup response.')
        payload = base64.b64decode(row['body_base64'], validate=True)
        if hashlib.sha256(payload).hexdigest() != row['sha256']:
            raise ValueError('Last-good view backup checksum differs.')
        parsed = json.loads(payload)
        if not isinstance(parsed.get('data'), (dict, list)) or not isinstance(parsed.get('key'), str):
            raise ValueError('Unexpected last-good view payload.')
        with (folder / row['name']).open('xb') as output:
            output.write(payload)
        files.append({'file': row['name'], 'sha256': row['sha256'], 'bytes': len(payload), 'expires_at': parsed['expires_at']})
    if complete is None or not files:
        raise ValueError('No complete last-good dashboard view backup was obtained.')
    return files
