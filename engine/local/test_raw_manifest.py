import hashlib
import importlib.util
import json
import os
import shutil
import time
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('raw_manifest', Path(__file__).with_name('raw_manifest.py'))
raw = importlib.util.module_from_spec(spec)
spec.loader.exec_module(raw)


class RawManifestTests(unittest.TestCase):
    def manifest(self, body=b'raw'):
        return {'files': [{'path': 'provider-evidence/raw/2026/10/26/' + 'a' * 64 + '.json.gz',
                           'bytes': len(body), 'sha256': hashlib.sha256(body).hexdigest()}]}

    def test_fixed_null_list_has_no_directory_entries_or_new_files(self):
        manifest = raw.parse_manifest(json.dumps(self.manifest()))
        self.assertEqual(raw.tar_input(manifest), (manifest['files'][0]['path'] + '\0').encode())
        with tempfile.TemporaryDirectory() as folder:
            name = Path(folder) / manifest['files'][0]['path']
            name.parent.mkdir(parents=True)
            name.write_bytes(b'raw')
            (name.parent / ('b' * 64 + '.json.gz')).write_bytes(b'new')
            command = ['tar', '--null', '--no-recursion', '-czf', '-', '-C', folder, '-T', '-']
            result = subprocess.run(command, input=raw.tar_input(manifest), capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            import io
            import tarfile
            with tarfile.open(fileobj=io.BytesIO(result.stdout), mode='r:gz') as archive:
                self.assertEqual(archive.getnames(), [manifest['files'][0]['path']])
            raw.verify_files(manifest, Path(folder), 1)

    def test_source_changes_or_missing_files_fail_validation(self):
        manifest = self.manifest()
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(RuntimeError):
                raw.verify_files(manifest, folder, 1)
            name = Path(folder) / manifest['files'][0]['path']
            name.parent.mkdir(parents=True)
            name.write_bytes(b'bad')
            with self.assertRaises(RuntimeError):
                raw.verify_files(manifest, folder, 1)
            name.write_bytes(b'raw')
            with self.assertRaises(RuntimeError):
                raw.verify_files(manifest, folder, 2)

    def test_unsafe_duplicate_empty_and_invalid_metadata_are_rejected(self):
        candidates = [{'files': []}]
        for path in ['/etc/passwd', '../raw.json.gz', 'provider-evidence/raw/--option.json.gz']:
            manifest = self.manifest()
            manifest['files'][0]['path'] = path
            candidates.append(manifest)
        duplicate = self.manifest()
        duplicate['files'] *= 2
        candidates.append(duplicate)
        for field, value in [('bytes', -1), ('bytes', True), ('sha256', 'wrong')]:
            manifest = self.manifest()
            manifest['files'][0][field] = value
            candidates.append(manifest)
        for manifest in candidates:
            with self.subTest(manifest=manifest), self.assertRaises(RuntimeError):
                raw.parse_manifest(json.dumps(manifest))

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI required for source manifest integration')
    def test_source_manifest_defers_recent_files_and_ignores_temporary_files(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder) / 'provider-evidence/raw/2026/10/26'
            base.mkdir(parents=True)
            stable = base / ('a' * 64 + '.json.gz')
            stable.write_bytes(b'stable')
            os.utime(stable, (time.time() - 120, time.time() - 120))
            (base / ('b' * 64 + '.json.gz')).write_bytes(b'new')
            (base / 'unfinished.part').write_bytes(b'temp')
            script = raw.manifest_script().replace(b'/app/storage/app/private', folder.encode())
            result = subprocess.run(['php'], input=script, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            manifest = raw.parse_manifest(result.stdout)
            self.assertEqual(len(manifest['files']), 1)
            self.assertEqual(manifest['files'][0]['sha256'], hashlib.sha256(b'stable').hexdigest())
            self.assertEqual(manifest['files'][0]['bytes'], 6)
