import base64
import gzip
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from production_history_mirror import restore


class ProductionHistoryMirrorTest(unittest.TestCase):
    def fixture(self, root):
        original = b'{"queryUrl":"synthetic"}'
        sha = hashlib.sha256(original).hexdigest()
        plan = b'{}'
        manifest = json.dumps({'package_version': 'legacy-history-package-1.0.0',
            'plan_sha256': hashlib.sha256(plan).hexdigest(),
            'files': [{'file': sha + '.json', 'sha256': sha}]}).encode()
        digest = hashlib.sha256(manifest).hexdigest()
        descriptor = json.dumps({'version': 'production-history-package-1.0.0',
            'manifest_base64': base64.b64encode(manifest).decode(),
            'plan_base64': base64.b64encode(plan).decode(),
            'manifest_sha256': digest, 'plan_sha256': hashlib.sha256(plan).hexdigest()}).encode()
        rows = []
        for body, provenance, fields in [
            (original, {'origin': 'legacy_file', 'source_sha256': sha}, {'history_source_sha256': sha}),
            (descriptor, {'origin': 'production_history_package'}, {'history_package_sha256': digest}),
        ]:
            key = hashlib.sha256(body).hexdigest()
            path = 'provider-evidence/raw/2026/10/31/' + key + '.json.gz'
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(gzip.compress(json.dumps({'body_sha256': key,
                'body_base64': base64.b64encode(body).decode(), 'provenance': provenance}).encode()))
            rows.append({'_id': path, **fields})
        return rows, digest, sha, original

    def test_fresh_machine_recovers_exact_originals_and_reuses_package(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            rows, digest, sha, original = self.fixture(root)
            paths = restore(root, root, rows)
            self.assertEqual(len(paths), 1)
            self.assertEqual((Path(paths[0]) / 'files' / (sha + '.json')).read_bytes(), original)
            self.assertEqual(restore(root, root, rows), paths)
            self.assertEqual(restore(root, root, rows, [{'manifest_sha256': digest}]), [])

    def test_incomplete_raw_transfer_never_publishes_package(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            rows, digest, _, _ = self.fixture(root)
            with self.assertRaisesRegex(RuntimeError, 'missing'):
                restore(root, root, rows[1:])
            self.assertFalse((root / 'recovery/production-history-packages' / digest).exists())

    def test_existing_corrupted_package_stops_sync(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            rows, _, sha, _ = self.fixture(root)
            package = Path(restore(root, root, rows)[0])
            (package / 'files' / (sha + '.json')).write_bytes(b'changed')
            with self.assertRaises(RuntimeError):
                restore(root, root, rows)
