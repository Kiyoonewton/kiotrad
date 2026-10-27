import importlib.util
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('raw_seed', Path(__file__).with_name('raw_seed.py'))
seed = importlib.util.module_from_spec(spec)
spec.loader.exec_module(seed)


class RawSeedTests(unittest.TestCase):
    def test_only_completed_isolated_local_snapshot_is_copied(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            env = folder / 'env'
            env.write_text('LOCAL_DATABASE=kiotrad_local_20261026T000000Z\n')
            calls = []

            def compose(*args, **kwargs):
                calls.append(args)
                if 'mongosh' in args:
                    return SimpleNamespace(stdout=b'{"complete":true}')
                if 'mongodump' in args:
                    kwargs['stdout'].write(b'synthetic archive')
                if 'mongorestore' in args:
                    self.assertEqual(kwargs['stdin'].read(), b'synthetic archive')

            sync = SimpleNamespace(ENV=env, compose=compose)
            seed.seed_snapshot(sync, 'kiotrad_local_20261027T000000Z', folder)
            self.assertEqual(len(calls), 3)
            self.assertIn('--db=kiotrad_local_20261026T000000Z', calls[1])
            self.assertIn('--nsTo=kiotrad_local_20261027T000000Z.*', calls[2])
            self.assertFalse((folder / 'local-seed.archive').exists())

    def test_production_name_and_full_rebuild_never_copy(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            env = folder / 'env'
            env.write_text('LOCAL_DATABASE=kiotrad\n')
            def forbidden(*args, **kwargs):
                self.fail('Must not access a production-named database')
            sync = SimpleNamespace(ENV=env, compose=forbidden)
            seed.seed_snapshot(sync, 'kiotrad_local_20261027T000000Z', folder)
            seed.seed_snapshot(sync, 'kiotrad_local_20261027T000000Z', folder, full=True)

    def test_old_snapshot_requires_one_indexed_rebuild(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            env = folder / 'env'
            env.write_text('LOCAL_DATABASE=kiotrad_local_20261026T000000Z\n')
            calls = []
            def compose(*args, **kwargs):
                calls.append(args)
                return SimpleNamespace(stdout=b'null')
            seed.seed_snapshot(SimpleNamespace(ENV=env, compose=compose), 'kiotrad_local_20261027T000000Z', folder)
            self.assertEqual(len(calls), 1)
