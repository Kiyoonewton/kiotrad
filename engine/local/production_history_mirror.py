"""Recover completed production history packages from retained, indexed raw bytes."""
import base64
import gzip
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

from history_overlay import DATABASE, HASH, describe
from raw_manifest import PATTERN


def read_envelope(root, name):
    if not isinstance(name, str) or not PATTERN.fullmatch(name):
        raise RuntimeError('Unsafe production history raw path.')
    path = Path(root) / name
    if any(p.is_symlink() for p in [path, *path.parents]):
        raise RuntimeError('History raw evidence may not use symlinks.')
    with gzip.open(path, 'rb') as stream:
        envelope = json.load(stream)
    body = base64.b64decode(envelope['body_base64'], validate=True)
    if hashlib.sha256(body).hexdigest() != envelope.get('body_sha256'):
        raise RuntimeError('Production history raw checksum differs.')
    return envelope, body


def restore(root, raw_root, rows, registered=()):
    known = {r['manifest_sha256'] for r in registered}
    sources = {r['history_source_sha256']: r['_id'] for r in rows if r.get('history_source_sha256')}
    recovered = []
    for row in rows:
        digest = row.get('history_package_sha256')
        if not digest or digest in known:
            continue
        if not HASH.fullmatch(digest):
            raise RuntimeError('Invalid completed package identity.')
        envelope, body = read_envelope(raw_root, row['_id'])
        descriptor = json.loads(body)
        if (envelope.get('provenance', {}).get('origin') != 'production_history_package'
                or descriptor.get('version') != 'production-history-package-1.0.0'):
            raise RuntimeError('Invalid completed production package descriptor.')
        manifest_bytes = base64.b64decode(descriptor['manifest_base64'], validate=True)
        plan_bytes = base64.b64decode(descriptor['plan_base64'], validate=True)
        manifest = json.loads(manifest_bytes)
        if (hashlib.sha256(manifest_bytes).hexdigest() != digest
                or descriptor.get('manifest_sha256') != digest
                or hashlib.sha256(plan_bytes).hexdigest() != descriptor.get('plan_sha256')
                or manifest.get('plan_sha256') != descriptor['plan_sha256']):
            raise RuntimeError('Completed package manifest or plan differs.')
        for entry in manifest.get('files', []):
            if not HASH.fullmatch(str(entry.get('sha256', ''))) or entry.get('file') != entry['sha256'] + '.json':
                raise RuntimeError('Unsafe completed history source name.')
        destination = Path(root) / 'recovery/production-history-packages' / digest
        if destination.exists():
            describe(root, destination, digest)
            for entry in manifest['files']:
                path = destination / 'files' / entry['file']
                if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != entry['sha256']:
                    raise RuntimeError('Retained completed history package changed.')
        else:
            destination.parent.mkdir(parents=True, exist_ok=True)
            temporary = Path(tempfile.mkdtemp(prefix='.package-', dir=destination.parent))
            try:
                (temporary / 'files').mkdir()
                for entry in manifest['files']:
                    sha = entry['sha256']
                    if not HASH.fullmatch(sha) or entry['file'] != sha + '.json' or sha not in sources:
                        raise RuntimeError('Completed history package source is missing or unsafe.')
                    source_envelope, original = read_envelope(raw_root, sources[sha])
                    if hashlib.sha256(original).hexdigest() != sha:
                        raise RuntimeError('Completed history source differs.')
                    (temporary / 'files' / entry['file']).write_bytes(original)
                (temporary / 'plan.json').write_bytes(plan_bytes)
                (temporary / 'manifest.json').write_bytes(manifest_bytes)
                describe(root, temporary, digest)
                temporary.rename(destination)
            finally:
                if temporary.exists():
                    shutil.rmtree(temporary)
        recovered.append(str(destination))
        known.add(digest)
        print('Recovered completed production history package: ' + digest, flush=True)
    return recovered


def recover(sync, database, folder, registered):
    active = next((s.split('=', 1)[1] for s in sync.ENV.read_text().splitlines()
                   if s.startswith('LOCAL_DATABASE=')), '')
    if not DATABASE.fullmatch(database) or database == active:
        raise RuntimeError('Production package recovery requires isolated local staging.')
    script = ('db.getSiblingDB(' + json.dumps(database) + ').local_raw_files.find({$or:['
              '{history_package_sha256:{$exists:true}},{history_source_sha256:{$exists:true}}]},'
              '{_id:1,history_package_sha256:1,history_source_sha256:1})'
              '.forEach(r=>print(JSON.stringify(r)));')
    output = sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--eval', script,
                          stdout=subprocess.PIPE).stdout
    rows = [json.loads(line) for line in output.decode().splitlines() if line.strip()]
    return restore(sync.ROOT, folder, rows, registered)
