import json
from pathlib import Path
import tempfile
import unittest

from sync_timings import SyncTimings


class SyncTimingsTest(unittest.TestCase):
    def test_stages_and_nested_operations_record_durations_and_rebuild_reason(self):
        with tempfile.TemporaryDirectory() as tmp:
            tick = [0]
            with SyncTimings(tmp, clock=lambda: tick[0]) as timer:
                tick[0] = 2
                timer.stage('history')
                with timer.operation('history_import'):
                    tick[0] = 7
                with timer.operation('history_verification'):
                    tick[0] = 10
                timer.evidence(raw_files=20, reused_files=18, download_files=2, download_bytes=1024,
                               seed={'mode': 'incremental_seed', 'reason': 'compatible_completed_index'}, password='DO_NOT_SAVE')
                timer.stage('views')
                tick[0] = 14
            report = json.loads(timer.path.read_text())
            self.assertEqual(report['status'], 'complete')
            self.assertEqual(report['elapsed_seconds'], 14)
            self.assertEqual([r['seconds'] for r in report['stages']], [2, 8, 4])
            self.assertEqual([r['seconds'] for r in report['operations']], [5, 3])
            self.assertEqual(report['evidence']['seed']['mode'], 'incremental_seed')
            self.assertNotIn('DO_NOT_SAVE', timer.path.read_text())
            self.assertFalse(timer.path.with_suffix('.tmp').exists())

    def test_download_operations_record_distinct_times_and_allowlisted_size_only(self):
        with tempfile.TemporaryDirectory() as temporary:
            tick = [0]
            with SyncTimings(temporary, clock=lambda: tick[0]) as timer:
                timer.stage('download')
                with timer.operation('raw_transfer'):
                    tick[0] = 10
                with timer.operation('raw_extraction_validation'):
                    tick[0] = 13
                timer.evidence(archive_bytes=1234, command='DO_NOT_SAVE')
            report = json.loads(timer.path.read_text())
            self.assertEqual([r['seconds'] for r in report['operations']], [10, 3])
            self.assertEqual(report['evidence']['archive_bytes'], 1234)
            self.assertNotIn('DO_NOT_SAVE', timer.path.read_text())

    def test_failed_stage_is_saved_without_exception_details_or_commands(self):
        with tempfile.TemporaryDirectory() as tmp:
            timer = SyncTimings(tmp)
            with self.assertRaisesRegex(RuntimeError, 'secret-command'):
                with timer:
                    timer.stage('rebuild')
                    raise RuntimeError('secret-command password=private')
            text = timer.path.read_text()
            report = json.loads(text)
            self.assertEqual(report['status'], 'failed')
            self.assertEqual(report['stages'][-1]['stage'], 'rebuild')
            self.assertEqual(report['stages'][-1]['status'], 'failed')
            self.assertNotIn('secret-command', text)
            self.assertNotIn('private', text)

    def test_timing_storage_failure_does_not_replace_a_sync_failure(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / 'recovery').write_text('not a folder')
            with self.assertRaisesRegex(RuntimeError, 'original failure'):
                with SyncTimings(tmp):
                    raise RuntimeError('original failure')
