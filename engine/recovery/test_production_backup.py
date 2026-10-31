import base64
import hashlib
import gzip
import json
import subprocess
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from backup_production_history import plan_input
from production_backup_io import backup_raw, backup_views, restore_archives, restore_count
from production_backup_scripts import cleanup_script, fresh_script, target_guard, validation_script
from raw_manifest import manifest_script

TARGET = 'kiotrad_local_backup_check_20990101T000000000000Z_abcdef12'


class ProductionBackupTest(unittest.TestCase):
    def test_production_and_active_snapshot_names_can_never_be_restore_or_cleanup_targets(self):
        for name in ['kiotrad', 'admin', 'kiotrad_local_20261031T000000000000Z', TARGET + '.bad', '../kiotrad']:
            for function in (target_guard, fresh_script, cleanup_script):
                with self.assertRaises(ValueError):
                    function(name)
        self.assertEqual(target_guard(TARGET), TARGET)
        self.assertIn(b'Restore target already exists', fresh_script(TARGET))
        self.assertNotIn(b'kiotrad.*', cleanup_script(TARGET))

    def test_raw_sync_keeps_its_safety_lag_and_backup_includes_recent_finished_files(self):
        self.assertIn(b'$cutoff = time() - 60;', manifest_script())
        self.assertIn(b'$cutoff = time() - 0;', manifest_script(safety_lag=0))
        for lag in (-1, True, 61, '0'):
            with self.assertRaises(ValueError):
                manifest_script(lag)

    def test_native_restore_requires_unambiguous_success_and_zero_failures(self):
        self.assertEqual(restore_count(b'2026-10-31 42 document(s) restored successfully. 0 document(s) failed to restore.'), 42)
        for body in [b'', b'1 document(s) restored successfully. 1 document(s) failed to restore.',
                     b'0 document(s) restored successfully. 0 document(s) failed to restore.\n0 document(s) restored successfully. 0 document(s) failed to restore.']:
            with self.assertRaises(ValueError):
                restore_count(body)

    def test_altered_archive_never_reaches_native_restore(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'archive.gz').write_bytes(b'changed')
            class Local:
                def __init__(self):
                    self.tools = []
                def guard_local(self):
                    pass
                def verify_destination(self):
                    pass
                def compose(self, *args, **kwargs):
                    self.tools.append(args[3])
            local = Local()
            with self.assertRaisesRegex(ValueError, 'checksum'):
                restore_archives(local, [{'file': 'archive.gz', 'sha256': '0' * 64, 'collection': 'example'}], TARGET, root)
            self.assertNotIn('mongorestore', local.tools)

    def test_restore_validator_uses_archive_counts_and_capture_references(self):
        script = validation_script(TARGET, {'provider_captures': 2})
        self.assertIn(b'countDocuments', script)
        self.assertIn(b'Restored checkpoint capture missing', script)
        self.assertIn(b'secondary_indexes_restored:false', script)
        self.assertNotIn(b'createIndex', script)

    def test_restore_validation_handles_non_iterable_mongosh_find_cursors(self):
        for capture_id, success in [('known', True), ('missing', False)]:
            prefix = """
const rows=[{capture_id:CAPTURE}];
const fixture={getCollectionNames:()=>['provider_captures','provider_checkpoints'],
 getCollection:()=>({countDocuments:()=>1,find:()=>({forEach:callback=>rows.forEach(callback)})}),
 provider_captures:{findOne:query=>query._id==='known'?{_id:'known'}:null}};
const db={getSiblingDB:()=>fixture};
const print=value=>process.stdout.write(value);
""".replace('CAPTURE', json.dumps(capture_id))
            code = prefix + validation_script(TARGET, {'provider_captures': 1, 'provider_checkpoints': 1}).decode()
            result = subprocess.run(['node', '-e', code], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(result.returncode == 0, success)
            if success:
                self.assertEqual(json.loads(result.stdout)['capture_references_checked'], 1)

    def test_last_good_views_preserve_exact_bytes_and_reject_bad_checksums(self):
        with tempfile.TemporaryDirectory() as tmp:
            payload = json.dumps({'key': 'example', 'data': {'status': 'available'}, 'expires_at': '2026-10-31T12:00:00Z'}).encode()
            row = {'type': 'view', 'name': 'a' * 64 + '.json', 'sha256': hashlib.sha256(payload).hexdigest(),
                   'body_base64': base64.b64encode(payload).decode()}
            response = (json.dumps(row) + '\n' + json.dumps({'type': 'complete', 'files': 1}) + '\n').encode()
            root = Path(tmp)
            with patch('production_backup_io.remote', return_value=response):
                files = backup_views('root@example.test', root)
            self.assertEqual((root / 'dashboard-views' / row['name']).read_bytes(), payload)
            self.assertEqual(files[0]['sha256'], row['sha256'])
            (root / 'second').mkdir()
            row['sha256'] = '0' * 64
            with patch('production_backup_io.remote', return_value=(json.dumps(row) + '\n').encode()):
                with self.assertRaisesRegex(ValueError, 'checksum'):
                    backup_views('root@example.test', root / 'second')

    def test_verified_raw_reuse_creates_independent_bytes_and_checks_body_hash(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            previous, target = root / 'previous', root / 'backup'
            target.mkdir()
            relative = 'provider-evidence/raw/2026/10/31/' + 'a' * 64 + '.json.gz'
            source = previous / relative
            source.parent.mkdir(parents=True)
            body = b'original response bytes'
            envelope = {'body_base64': base64.b64encode(body).decode(), 'body_sha256': hashlib.sha256(body).hexdigest()}
            source.write_bytes(gzip.compress(json.dumps(envelope).encode()))
            original = source.read_bytes()
            manifest = {'cutoff_unix': 1, 'files': [{'path': relative, 'sha256': hashlib.sha256(original).hexdigest(), 'bytes': len(original)}]}
            with patch('production_backup_io.remote', return_value=json.dumps(manifest).encode()):
                result = backup_raw('root@example.test', target, [previous])
            self.assertEqual(result['copied_independent_files'], 1)
            self.assertNotEqual(source.stat().st_ino, (target / relative).stat().st_ino)
            source.write_bytes(b'changed cache')
            self.assertEqual((target / relative).read_bytes(), original)

    def test_incomplete_or_blocked_plan_cannot_start_backup(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'plan.json'
            path.write_text(json.dumps({'version': 'production-history-plan-1.0.0', 'mode': 'read_only_production_plan', 'applied': False,
                                        'blocked_seasons': [], 'provenance': {'inventory_completion': {'applied': False}}}))
            self.assertEqual(plan_input(path)[1], hashlib.sha256(path.read_bytes()).hexdigest())
            data = json.loads(path.read_text())
            data['blocked_seasons'] = ['example']
            path.write_text(json.dumps(data))
            with self.assertRaises(ValueError):
                plan_input(path)


if __name__ == '__main__':
    unittest.main()
