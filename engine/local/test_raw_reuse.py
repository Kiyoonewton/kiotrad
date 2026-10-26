import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('raw_reuse', Path(__file__).with_name('raw_reuse.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RawReuseTests(unittest.TestCase):
    def test_reuses_verified_evidence_and_downloads_missing_or_corrupt_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            old, new = root / 'old', root / 'new'
            old.mkdir()
            new.mkdir()
            (old / 'good').write_bytes(b'good')
            (old / 'bad').write_bytes(b'xxxx')
            entries = [{'path': name, 'bytes': 4, 'sha256': hashlib.sha256(b'good').hexdigest()}
                       for name in ['good', 'bad', 'missing']]
            delta, count = module.reuse_files({'files': entries}, new)
            self.assertEqual(count, 1)
            self.assertEqual([e['path'] for e in delta['files']], ['bad', 'missing'])
            self.assertEqual((new / 'good').read_bytes(), b'good')
            (old / 'good').unlink()
            self.assertEqual((new / 'good').read_bytes(), b'good')

    def test_no_previous_snapshot_downloads_everything(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary) / 'new'
            folder.mkdir()
            manifest = {'files': [{'path': 'missing', 'bytes': 1, 'sha256': 'a' * 64}]}
            self.assertEqual(module.reuse_files(manifest, folder), (manifest, 0))
