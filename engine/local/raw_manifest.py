"""Freeze raw file membership without pausing production collection."""
import hashlib
import json
import re
from pathlib import Path

PATTERN = re.compile(r'provider-evidence/raw/\d{4}/\d{2}/\d{2}/[a-f0-9]{64}\.json\.gz\Z')


def manifest_script():
    # No database connection, credentials or source mutation. A short safety lag
    # excludes files that an older collector may still be writing directly.
    return b'''<?php
$root = '/app/storage/app/private';
$base = $root.'/provider-evidence/raw';
$cutoff = time() - 60;
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isLink() || !$file->isFile() || $file->getMTime() > $cutoff) continue;
    $path = substr($file->getPathname(), strlen($root) + 1);
    if (!preg_match('~^provider-evidence/raw/[0-9]{4}/[0-9]{2}/[0-9]{2}/[a-f0-9]{64}\\.json\\.gz$~D', $path)) continue;
    $hash = hash_file('sha256', $file->getPathname());
    if ($hash === false) throw new RuntimeException('Raw manifest read failed');
    $files[] = ['path' => $path, 'sha256' => $hash, 'bytes' => $file->getSize()];
}
usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
echo json_encode(['cutoff_unix' => $cutoff, 'files' => $files], JSON_THROW_ON_ERROR);
'''


def parse_manifest(payload):
    manifest = json.loads(payload)
    files = manifest.get('files')
    if not isinstance(files, list) or not files:
        raise RuntimeError('No stable raw files available; retry after collection has saved evidence.')
    seen = set()
    for entry in files:
        if not isinstance(entry, dict):
            raise RuntimeError('Invalid raw file manifest.')
        name = entry.get('path', '')
        if not isinstance(name, str) or not PATTERN.fullmatch(name) or name in seen:
            raise RuntimeError('Unsafe or duplicate raw manifest path.')
        if not isinstance(entry.get('sha256'), str) or not re.fullmatch('[a-f0-9]{64}', entry['sha256']):
            raise RuntimeError('Invalid raw manifest checksum.')
        if type(entry.get('bytes')) is not int or entry['bytes'] < 1:
            raise RuntimeError('Invalid raw manifest size.')
        seen.add(name)
    return manifest


def tar_input(manifest):
    return b''.join(entry['path'].encode('ascii') + b'\0' for entry in manifest['files'])


def verify_files(manifest, folder, count):
    if count != len(manifest['files']):
        raise RuntimeError('Raw snapshot file count differs from its manifest.')
    for entry in manifest['files']:
        path = Path(folder) / entry['path']
        if not path.is_file() or path.stat().st_size != entry['bytes']:
            raise RuntimeError('Raw snapshot file missing or changed during download; retry sync.')
        digest = hashlib.sha256()
        with path.open('rb') as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b''):
                digest.update(chunk)
        if digest.hexdigest() != entry['sha256']:
            raise RuntimeError('Raw snapshot changed during download; retry sync.')
