"""CI-only native backup/restore contract against disposable synthetic records."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile

from production_backup_io import restore_archives
from production_backup_scripts import cleanup_script, metadata_script

if os.environ.get('CI') != 'true':
    raise SystemExit('Backup smoke is restricted to disposable CI.')
ROOT = Path(__file__).resolve().parents[2]
COMMAND = ['docker', 'compose', '--env-file', str(ROOT / 'engine/.env'), '-f', str(ROOT / 'engine/compose.yaml'), 'exec', '-T', 'mongodb']
ADMIN = 'mongodb://ci_fixture:ci_fixture_password@127.0.0.1:27017/?authSource=admin'
TARGET = 'kiotrad_local_backup_check_20990101T000000000000Z_abcdef12'
PASSWORD = secrets.token_hex(24)
URI = 'mongodb://backup_read_smoke:' + PASSWORD + '@127.0.0.1:27017/kiotrad?authSource=kiotrad'


def shell(script, auth=True):
    payload = script if isinstance(script, bytes) else script.encode()
    if auth:
        payload = ('db=new Mongo(' + json.dumps(ADMIN) + ').getDB("test");\n').encode() + payload
    result = subprocess.run(COMMAND + ['mongosh', '--quiet', '--nodb', '--file', '/dev/stdin'],
                            input=payload, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        detail = result.stderr.decode(errors='replace').replace(ADMIN, '<ci-admin-uri>').replace(URI, '<ci-read-uri>').replace(PASSWORD, '<fixture-password>')
        raise RuntimeError('CI backup shell failed: ' + detail)
    return result.stdout



class Fixture:
    def guard_local(self):
        if os.environ.get('CI') != 'true':
            raise RuntimeError('CI fixture only.')

    def verify_destination(self):
        self.guard_local()

    def compose(self, *args, **kwargs):
        if args[:3] != ('exec', '-T', 'mongodb') or args[3] not in ('mongosh', 'mongorestore'):
            raise RuntimeError('Unexpected CI restore command.')
        kwargs.setdefault('stderr', subprocess.PIPE)
        if args[3] == 'mongosh':
            kwargs['input'] = ('db=new Mongo(' + json.dumps(ADMIN) + ').getDB("test");\n').encode() + kwargs['input']
            result = subprocess.run(COMMAND + ['mongosh', '--nodb', *args[4:]], **kwargs)
            if result.returncode:
                detail = result.stderr.decode(errors='replace').replace(ADMIN, '<ci-admin-uri>').replace(URI, '<ci-read-uri>').replace(PASSWORD, '<fixture-password>')
                raise RuntimeError('CI restore validation failed: ' + detail)
            return result
        return subprocess.run(COMMAND + [args[3], '--uri=' + ADMIN, *args[4:]], check=True, **kwargs)


created = False
try:
    shell('''
const d=db.getSiblingDB('kiotrad');
if(d.provider_captures.findOne({_id:'synthetic-backup-check'}) || d.getCollectionNames().includes('backup_smoke_empty'))throw Error('Fixture collision');
if(db.getMongo().getDBNames().includes(''' + json.dumps(TARGET) + '''))throw Error('Target collision');
d.provider_captures.insertOne({_id:'synthetic-backup-check',competition:'virtual-euro',season_id:'backup-smoke',
 observed_at:'2099-01-01T00:00:00+00:00',matches:[],odds_snapshots:[],
 bson_date:new Date('2026-10-31T00:00:00Z'),bson_long:NumberLong('9223372036854775806')});
d.provider_checkpoints.insertOne({_id:'synthetic-backup-check',capture_id:'synthetic-backup-check'});
d.createCollection('backup_smoke_empty');
d.createUser({user:'backup_read_smoke',pwd:''' + json.dumps(PASSWORD) + ''',roles:[{role:'read',db:'kiotrad'}]});
''')
    created = True
    metadata = json.loads(shell(metadata_script(URI), False))
    assert metadata['read_only_role_verified'] is True
    archives = []
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        for name in ['provider_captures', 'provider_checkpoints', 'backup_smoke_empty']:
            path = root / (name + '.gz')
            query = ['--query=' + json.dumps({'_id': 'synthetic-backup-check'})] if name != 'backup_smoke_empty' else []
            with path.open('wb') as output:
                subprocess.run(COMMAND + ['mongodump', '--config=/dev/stdin', '--db=kiotrad', '--collection=' + name, '--archive', '--gzip', *query],
                               input=json.dumps({'uri': URI}).encode(), stdout=output, stderr=subprocess.PIPE, check=True)
            archives.append({'collection': name, 'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()})
        result = restore_archives(Fixture(), archives, TARGET, root)
        assert result['verified'] is True
        assert result['counts']['backup_smoke_empty'] == 0
        assert result['capture_references_checked'] > 0
        shell('''
const r=db.getSiblingDB(''' + json.dumps(TARGET) + ''').provider_captures.findOne({_id:'synthetic-backup-check'});
if(!(r.bson_date instanceof Date)||r.bson_long.toString()!=='9223372036854775806')throw Error('BSON types changed');
''')
    print('Production backup native archive counts, empty collection, checkpoint references and BSON types passed.')
finally:
    if created:
        shell(cleanup_script(TARGET))
        shell('''
const d=db.getSiblingDB('kiotrad');
d.provider_checkpoints.deleteOne({_id:'synthetic-backup-check'});
d.provider_captures.deleteOne({_id:'synthetic-backup-check'});
d.backup_smoke_empty.drop();
d.dropUser('backup_read_smoke');
''')
