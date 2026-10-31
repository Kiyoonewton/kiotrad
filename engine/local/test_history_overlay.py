import hashlib
import json
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import Mock, patch

import history_overlay as overlay
import raw_sync


class HistoryOverlayTests(unittest.TestCase):
    def package(self, root, name='legacy-history-batch-1'):
        folder = root / 'recovery' / name
        folder.mkdir(parents=True)
        (folder / 'plan.json').write_text('{}')
        manifest = {'package_version': 'legacy-history-package-1.0.0',
                    'plan_sha256': hashlib.sha256(b'{}').hexdigest(),
                    'files': [{'file': 'source.json'}]}
        (folder / 'manifest.json').write_text(json.dumps(manifest))
        return folder

    def sync(self, root, calls=None, fail_import=False):
        calls = calls if calls is not None else []
        env = root / 'env'
        env.write_text('LOCAL_DATABASE=kiotrad_local_20261029T000000Z\nAPP_KEY=private\n')

        def compose(*args, **kwargs):
            if '-r' in args:
                return SimpleNamespace(stdout=b'a' * 64)
            if 'provider:import-legacy-history' in args:
                calls.append('import')
                if fail_import:
                    raise RuntimeError('history import failed')
            if 'provider:verify-legacy-history' in args:
                calls.append('verify')
            return SimpleNamespace(stdout=b'')

        return SimpleNamespace(ROOT=root, ENV=env, compose=Mock(side_effect=compose),
                               verify_destination=Mock())

    def test_registration_replays_on_next_sync_and_does_not_target_active_database(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = self.package(root)
            records = overlay.packages(root, [str(folder)])
            sync = self.sync(root)
            original = sync.ENV.read_bytes()
            overlay.replay(sync, 'kiotrad_local_20261030T000000Z', records)
            self.assertEqual(overlay.packages(root), records)
            self.assertEqual(sync.ENV.read_bytes(), original)
            command = next(c.args for c in sync.compose.call_args_list if 'provider:import-legacy-history' in c.args)
            self.assertIn('MONGODB_DATABASE=kiotrad_local_20261030T000000Z', command)
            self.assertIn(str(folder) + ':/legacy-history:ro', command)
            self.assertIn(str(root / 'recovery/local-history-evidence') + ':/history-evidence', command)
            overlay.replay(sync, 'kiotrad_local_20261031T000000Z', overlay.packages(root))
            self.assertEqual(sum('provider:import-legacy-history' in c.args for c in sync.compose.call_args_list), 2)
            with self.assertRaises(RuntimeError):
                overlay.replay(sync, 'kiotrad_local_20261029T000000Z', records)
            with self.assertRaises(RuntimeError):
                overlay.replay(sync, 'kiotrad', records)

    def test_missing_changed_or_unsafe_registered_package_fails_closed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = self.package(root)
            records = overlay.packages(root, [str(folder)])
            overlay.save(root, records)
            original = (folder / 'manifest.json').read_text()
            (folder / 'manifest.json').write_text(original + '\n')
            with self.assertRaises(RuntimeError):
                overlay.packages(root)
            (folder / 'manifest.json').write_text(original)
            folder.rename(folder.with_name('moved'))
            with self.assertRaises(RuntimeError):
                overlay.packages(root)
            moved = folder.with_name('moved')
            repaired = overlay.packages(root, [str(moved)])
            self.assertEqual(len(repaired), 1)
            self.assertEqual(repaired[0]['path'], str(moved))
            link = root / 'linked'
            link.symlink_to(moved, target_is_directory=True)
            with self.assertRaises(RuntimeError):
                overlay.describe(root, link)

    def test_failed_import_does_not_register_or_modify_active_environment(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            records = overlay.packages(root, [str(self.package(root))])
            sync = self.sync(root, fail_import=True)
            original = sync.ENV.read_bytes()
            with self.assertRaises(RuntimeError):
                overlay.replay(sync, 'kiotrad_local_20261030T000000Z', records)
            self.assertEqual(sync.ENV.read_bytes(), original)
            self.assertFalse(overlay.registry_path(root).exists())

    def test_pruned_backup_directory_and_changed_plan_are_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = self.package(root)
            (folder / 'plan.json').write_text('{"changed":true}')
            with self.assertRaises(RuntimeError):
                overlay.describe(root, folder)
            backup = root / 'backups' / 'package'
            backup.mkdir(parents=True)
            with self.assertRaises(RuntimeError):
                overlay.describe(root, backup)

    def perform(self, root, calls, fail_import=False, full=False, requested=()):
        sync = self.sync(root, calls, fail_import)
        sync.run = Mock(side_effect=[SimpleNamespace(stdout=b'{"capital_minor":5000000}'), SimpleNamespace(stdout=b'manifest')])
        sync.ssh_command = Mock(return_value=['ssh'])
        sync.getpass = Mock(return_value='synthetic')
        sync.quote = lambda text, safe: text
        sync.source_collections = Mock(return_value=[])
        sync.validation_script = Mock(return_value='validate')
        sync.activate_snapshot = Mock(side_effect=lambda *a: calls.append('activate'))
        with patch.object(raw_sync, 'parse_manifest', return_value={'files': []}), \
             patch.object(raw_sync, 'reuse_files', return_value=({'files': []}, 1)), \
             patch.object(raw_sync, 'verify_files'), \
             patch('raw_seed.seed_snapshot'), \
             patch('prewarm.prepare', side_effect=lambda *a: calls.append('warm')), \
             patch('snapshot_retention.prune', side_effect=lambda *a: calls.append('prune')):
            raw_sync.perform(sync, 'source', full, requested)
        return sync

    def test_both_full_and_incremental_sync_import_before_warming_activation_and_cleanup(self):
        for full in [False, True]:
            with self.subTest(full=full), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                folder = self.package(root)
                calls = []
                sync = self.perform(root, calls, full=full, requested=[str(folder)])
                rebuild = next(c.args for c in sync.compose.call_args_list if 'provider:rebuild-raw' in c.args)
                self.assertEqual(rebuild[rebuild.index('php'):],
                                 ('php', '-d', 'memory_limit=512M', 'artisan', 'provider:rebuild-raw', '/raw-evidence'))
                self.assertEqual(calls, ['import', 'verify', 'warm', 'activate', 'prune'])
                calls = []
                self.perform(root, calls, full=full)
                self.assertEqual(calls, ['import', 'verify', 'warm', 'activate', 'prune'])

    def test_verification_failure_keeps_current_snapshot_active(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = self.package(root)
            calls = []
            original_sync = self.sync

            def failing_sync(*args, **kwargs):
                sync = original_sync(*args, **kwargs)
                original_compose = sync.compose.side_effect

                def compose(*command, **options):
                    if 'provider:verify-legacy-history' in command:
                        calls.append('verification_failed')
                        raise RuntimeError('Pinned evidence differs')
                    return original_compose(*command, **options)

                sync.compose.side_effect = compose
                return sync

            with patch.object(self, 'sync', side_effect=failing_sync):
                with self.assertRaisesRegex(RuntimeError, 'Pinned evidence differs'):
                    self.perform(root, calls, requested=[str(folder)])
            self.assertEqual(calls, ['import', 'verification_failed'])
            self.assertIn('LOCAL_DATABASE=kiotrad_local_20261029T000000Z', (root / 'env').read_text())

    def test_overlay_failure_stops_sync_before_warming_activation_or_retention(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = self.package(root)
            calls = []
            with self.assertRaises(RuntimeError):
                self.perform(root, calls, fail_import=True, requested=[str(folder)])
            self.assertEqual(calls, ['import'])
            self.assertNotIn('SIMULATION_CAPITAL_NAIRA', (root / 'env').read_text())
