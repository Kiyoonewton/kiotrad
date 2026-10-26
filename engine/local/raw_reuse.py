"""Reuse checksum-verified immutable evidence from previous local snapshots."""
import hashlib
import os
import shutil


def reuse_files(manifest, folder):
    pending = []
    reused = 0
    roots = sorted(folder.parent.iterdir(), reverse=True)
    for entry in manifest['files']:
        found = None
        for root in roots:
            if root == folder or not root.is_dir() or root.is_symlink():
                continue
            candidate = root / entry['path']
            if not candidate.is_file() or candidate.is_symlink():
                continue
            if candidate.stat().st_size != entry['bytes']:
                continue
            digest = hashlib.sha256(candidate.read_bytes()).hexdigest()
            if digest == entry['sha256']:
                found = candidate
                break
        if found is None:
            pending.append(entry)
            continue
        target = folder / entry['path']
        target.parent.mkdir(parents=True, exist_ok=True)
        try:
            os.link(found, target)
        except OSError:
            shutil.copyfile(found, target)
        reused += 1
    return {**manifest, 'files': pending}, reused
