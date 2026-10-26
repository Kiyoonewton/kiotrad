import base64
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('raw_sync', Path(__file__).with_name('raw_sync.py'))
raw = importlib.util.module_from_spec(spec)
spec.loader.exec_module(raw)


class RawSyncTests(unittest.TestCase):
    def archive(self, path, name, body=b'{}', link=False):
        record = gzip.compress(json.dumps({'body_base64': base64.b64encode(body).decode(),
            'body_sha256': hashlib.sha256(body).hexdigest()}).encode())
        with tarfile.open(path, 'w:gz') as archive:
            member = tarfile.TarInfo(name)
            if link:
                member.type = tarfile.SYMTYPE
                member.linkname = '/etc/passwd'
            else:
                member.size = len(record)
            archive.addfile(member, None if link else io.BytesIO(record))

    def test_only_raw_regular_files_are_extracted(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'raw.tar.gz'
            self.archive(path, 'provider-evidence/raw/2026/10/26/test.json.gz')
            self.assertEqual(raw.extract_evidence(path, Path(folder) / 'out'), 1)

    def test_links_traversal_and_unrelated_files_are_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'raw.tar.gz'
            for name, link in [('provider-evidence/raw/../../escape.json.gz', False),
                               ('provider-evidence/raw/link.json.gz', True), ('secrets.json.gz', False)]:
                self.archive(path, name, link=link)
                with self.assertRaises(RuntimeError):
                    raw.extract_evidence(path, Path(folder) / 'out')
