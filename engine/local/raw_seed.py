"""Clone a completed local derived snapshot; never read production databases."""
import json
import re
import subprocess


DATABASE = re.compile(r'kiotrad_local_[0-9TZ]+')


def seed_snapshot(sync, destination, folder, full=False):
    if full:
        print('Explicit full rebuild requested.', flush=True)
        return
    source = next((line.split('=', 1)[1] for line in sync.ENV.read_text().splitlines()
                   if line.startswith('LOCAL_DATABASE=')), '')
    if not DATABASE.fullmatch(source) or not DATABASE.fullmatch(destination) or source == destination:
        print('No eligible local seed; building the initial processing index.', flush=True)
        return
    script = 'print(JSON.stringify(db.getSiblingDB(' + json.dumps(source) + ').local_raw_rebuild.findOne({_id:"state"})))'
    state = json.loads(sync.compose('exec', '-T', 'mongodb', 'mongosh', '--quiet', '--eval', script,
                                   stdout=subprocess.PIPE).stdout)
    if not state or state.get('complete') is not True:
        print('Previous snapshot has no completed processing index; one full rebuild is required.', flush=True)
        return
    print('Copying completed local views into an isolated staging database...', flush=True)
    archive = folder / 'local-seed.archive'
    try:
        with archive.open('wb') as output:
            sync.compose('exec', '-T', 'mongodb', 'mongodump', '--db=' + source,
                         '--archive', stdout=output)
        with archive.open('rb') as incoming:
            sync.compose('exec', '-T', 'mongodb', 'mongorestore', '--archive',
                         '--nsFrom=' + source + '.*', '--nsTo=' + destination + '.*',
                         '--stopOnError', stdin=incoming)
    finally:
        archive.unlink(missing_ok=True)
