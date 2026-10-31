import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

from audit_raw_documents import audit


class RawAuditLauncherTest(unittest.TestCase):
    def test_active_snapshot_is_mounted_read_only_and_no_sync_or_activation_occurs(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            database = 'kiotrad_local_20261031T175420856465Z'
            source = root / 'backups/raw-sync/20261031T175420856465Z'
            source.mkdir(parents=True)
            (source / 'raw-files.json').write_text('{}')
            env = root / 'env'
            env.write_text('LOCAL_DATABASE=' + database + '\n')
            calls = []
            def compose(*args, **kwargs):
                calls.append(args)
                if args[0] == 'run':
                    destination = next(root.glob('recovery/raw-document-audit/*'))
                    (destination / 'report.jsonl').write_text(json.dumps({'type': 'summary',
                        'complete': True, 'database': database, 'reasons': {}}) + '\n')
            sync = SimpleNamespace(ROOT=root, ENV=env, guard_local=Mock(), verify_destination=Mock(), compose=compose)
            destination = audit(sync)
            self.assertTrue((destination / 'rejections.csv').is_file())
            self.assertEqual(len(calls), 2)
            self.assertIn(str(source.resolve()) + ':/raw-evidence:ro', calls[1])
            self.assertIn('MONGODB_DATABASE=' + database, calls[1])
            self.assertNotIn('--apply', calls[1])
            self.assertEqual(env.read_text(), 'LOCAL_DATABASE=' + database + '\n')

    def test_non_snapshot_database_stops_before_any_compose_command(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            env = root / 'env'
            env.write_text('LOCAL_DATABASE=kiotrad\n')
            sync = SimpleNamespace(ROOT=root, ENV=env, guard_local=Mock(), verify_destination=Mock(), compose=Mock())
            with self.assertRaises(RuntimeError):
                audit(sync)
            sync.compose.assert_not_called()

    def test_subset_report_is_mounted_readonly_and_join_coverage_is_exported(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            database = 'kiotrad_local_20261031T175420856465Z'
            source = root / 'backups/raw-sync/20261031T175420856465Z'
            source.mkdir(parents=True)
            (source / 'raw-files.json').write_text('{}')
            env = root / 'env'
            env.write_text('LOCAL_DATABASE=' + database + '\n')
            previous = root / 'previous.jsonl'
            previous.write_text('synthetic previous report')
            calls = []
            def compose(*args, **kwargs):
                calls.append(args)
                if args[0] == 'run':
                    destination = next(root.glob('recovery/raw-document-audit/*'))
                    rows = [{'type': 'validated_odds', 'source_price_coverage': {
                        'source_prices': 1, 'matching_existing': 1, 'different_existing': 0, 'missing': 0}},
                        {'type': 'summary', 'complete': True, 'database': database, 'reasons': {}}]
                    (destination / 'report.jsonl').write_text('\n'.join(json.dumps(r) for r in rows))
            sync = SimpleNamespace(ROOT=root, ENV=env, guard_local=Mock(), verify_destination=Mock(), compose=compose)
            destination = audit(sync, previous)
            self.assertIn(str(root) + ':/previous-audit:ro', calls[1])
            self.assertIn('--rejections-from=/previous-audit/previous.jsonl', calls[1])
            self.assertEqual(len((destination / 'join-context.csv').read_text().splitlines()), 2)
            self.assertEqual(previous.read_text(), 'synthetic previous report')
