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

class DownloadMeasurementTests(unittest.TestCase):
    archive = RawSyncTests.archive

    def test_transfer_and_extraction_are_timed_without_changing_the_raw_bytes(self):
        from sync_timings import SyncTimings
        from unittest.mock import Mock
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'source.tar.gz'
            name = 'provider-evidence/raw/2026/10/26/' + 'a' * 64 + '.json.gz'
            self.archive(source, name)
            target = root / 'snapshot'
            target.mkdir()
            sync = Mock()
            sync.ssh_command.side_effect = lambda server, command: command

            def transfer(command, **kwargs):
                self.assertIn('-czf', command)
                self.assertEqual(kwargs['input'], name.encode() + b'\0')
                kwargs['stdout'].write(source.read_bytes())

            sync.run.side_effect = transfer
            with SyncTimings(root) as timings:
                result = raw.download_evidence(sync, 'synthetic-host', {'files': [{'path': name}]}, target, timings)
            self.assertEqual(result, 1)
            report = json.loads(timings.path.read_text())
            self.assertEqual([r['operation'] for r in report['operations']], ['raw_transfer', 'raw_extraction_validation'])
            self.assertTrue(all(r['status'] == 'complete' for r in report['operations']))
            self.assertEqual(report['evidence']['archive_bytes'], source.stat().st_size)
            with tarfile.open(source) as archive:
                self.assertEqual((target / name).read_bytes(), archive.extractfile(name).read())

    def test_failed_transfer_records_failure_and_never_extracts_or_claims_success(self):
        from sync_timings import SyncTimings
        from unittest.mock import Mock, patch
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sync = Mock()
            sync.run.side_effect = RuntimeError('synthetic-private-error')
            timer = SyncTimings(root)
            with patch.object(raw, 'extract_evidence') as extract:
                with self.assertRaises(RuntimeError), timer:
                    raw.download_evidence(sync, 'synthetic-host', {'files': [{'path': 'raw'}]}, root, timer)
            extract.assert_not_called()
            report = json.loads(timer.path.read_text())
            self.assertEqual(report['operations'][0]['status'], 'failed')
            self.assertNotIn('synthetic-private-error', timer.path.read_text())

    def test_empty_delta_does_not_start_transfer_or_create_an_archive(self):
        from unittest.mock import Mock
        with tempfile.TemporaryDirectory() as temporary:
            sync, timer = Mock(), Mock()
            root = Path(temporary)
            self.assertEqual(raw.download_evidence(sync, 'synthetic-host', {'files': []}, root, timer), 0)
            sync.run.assert_not_called()
            timer.operation.assert_not_called()
            self.assertFalse((root / 'raw.tar.gz').exists())
