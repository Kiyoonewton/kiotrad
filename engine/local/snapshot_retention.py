"""Keep current and one previous isolated local snapshot after successful activation."""
import json
import re
import shutil
import subprocess

STAMP = re.compile(r'\d{8}T\d{12}Z')
DATABASE = re.compile(r'kiotrad_local_(\d{8}T\d{12}Z)')


def obsolete(names, current, previous):
    keep = {current, previous}
    return [name for name in names if DATABASE.fullmatch(name) and name not in keep]


def prune(sync, current, previous):
    if not DATABASE.fullmatch(current):
        raise RuntimeError('Retention requires a timestamped local snapshot.')
    sync.verify_destination()
    result = sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--eval',
                          'print(JSON.stringify(db.getMongo().getDBNames()))', stdout=subprocess.PIPE)
    names = json.loads(result.stdout)
    for name in obsolete(names, current, previous):
        script = 'new Mongo().getDB(' + json.dumps(name) + ').dropDatabase();'
        sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--eval', script)
    keep_stamps = {name.removeprefix('kiotrad_local_') for name in [current, previous] if name}
    for kind in ['raw-sync', 'local-sync']:
        root = sync.ROOT / 'backups' / kind
        if not root.exists() or root.is_symlink():
            continue
        for folder in root.iterdir():
            if folder.is_dir() and not folder.is_symlink() and STAMP.fullmatch(folder.name) and folder.name not in keep_stamps:
                shutil.rmtree(folder)
    print('Local retention: current snapshot and one previous copy kept. Production untouched.')
