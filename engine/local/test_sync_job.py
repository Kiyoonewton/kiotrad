import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

from sync_credentials import save
from sync_job import SyncJob


class SyncJobTest(unittest.TestCase):
    def root(self, folder):
        root = Path(folder)
        (root / 'engine/local').mkdir(parents=True)
        (root / 'engine/local/.env.local').write_text('LOCAL_DATABASE=kiotrad_local_20261030T000000Z\n')
        save(root, 'root@example.test', 'synthetic-secret')
        return root

    def test_single_flight_and_sanitized_progress(self):
        with tempfile.TemporaryDirectory() as temporary:
            job = SyncJob(self.root(temporary))
            with patch('sync_job.threading.Thread') as thread:
                self.assertTrue(job.start())
                self.assertFalse(job.start())
                thread.assert_called_once()
            job.progress('synthetic-secret mongodb://private')
            self.assertNotIn('synthetic-secret', json.dumps(job.view()))
            job.progress('{"progress":"2/4","season":"private"}')
            self.assertEqual(job.view()['done'], 2)
            job.progress('2026-10-31 workbench: season_monitoring 20/40')
            self.assertEqual(job.view()['phase'], 'Checking seasons')
            self.assertEqual(job.view()['total'], 40)

    def test_success_requires_changed_active_snapshot_and_http_readiness(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = self.root(temporary)
            job = SyncJob(root)
            job.started = 1
            process = Mock()
            process.stdout = ['Downloading strategy definitions...\n', 'synthetic-secret\n']
            process.wait.return_value = 0

            def enter():
                (root / 'engine/local/.env.local').write_text('LOCAL_DATABASE=kiotrad_local_20261031T000000Z\n')
                return process

            context = Mock()
            context.__enter__ = Mock(side_effect=enter)
            context.__exit__ = Mock(return_value=False)
            with patch('sync_job.subprocess.Popen', return_value=context) as popen, patch.object(job, 'ready', return_value=True) as ready:
                job.run({'KIOTRAD_SYNC_SSH': 'root@example.test', 'KIOTRAD_SYNC_PASSWORD': 'synthetic-secret'})
            self.assertEqual(job.view()['status'], 'ready')
            ready.assert_called_once_with('kiotrad_local_20261031T000000Z')
            self.assertNotIn('synthetic-secret', json.dumps(job.view()))
            command = popen.call_args.args[0]
            self.assertNotIn('synthetic-secret', command)
            self.assertEqual(command[-2:], ['--ssh', 'root@example.test'])
            self.assertEqual(popen.call_args.kwargs['env']['KIOTRAD_SYNC_NONINTERACTIVE'], '1')

    def test_failed_sync_is_not_ready_and_never_probes_dashboard(self):
        with tempfile.TemporaryDirectory() as temporary:
            job = SyncJob(self.root(temporary))
            process = Mock(stdout=iter(['synthetic-secret\n']))
            process.wait.return_value = 1
            context = Mock()
            context.__enter__ = Mock(return_value=process)
            context.__exit__ = Mock(return_value=False)
            with patch('sync_job.subprocess.Popen', return_value=context), patch.object(job, 'ready') as ready:
                job.run({'KIOTRAD_SYNC_SSH': 'root@example.test', 'KIOTRAD_SYNC_PASSWORD': 'synthetic-secret'})
            self.assertEqual(job.view()['status'], 'failed')
            self.assertNotIn('synthetic-secret', json.dumps(job.view()))
            ready.assert_not_called()

    def test_exit_zero_without_activating_a_new_snapshot_is_not_success(self):
        with tempfile.TemporaryDirectory() as temporary:
            job = SyncJob(self.root(temporary))
            process = Mock(stdout=iter([]))
            process.wait.return_value = 0
            context = Mock()
            context.__enter__ = Mock(return_value=process)
            context.__exit__ = Mock(return_value=False)
            with patch('sync_job.subprocess.Popen', return_value=context), patch.object(job, 'ready') as ready:
                job.run({'KIOTRAD_SYNC_SSH': 'root@example.test', 'KIOTRAD_SYNC_PASSWORD': 'synthetic-secret'})
            self.assertEqual(job.view()['status'], 'failed')
            ready.assert_not_called()

    def test_http_readiness_requires_expected_database_overview_and_scenario(self):
        job = SyncJob('.')
        responses = [ {'status': 'ready'}, {'local_sync': {'database': 'expected'}, 'dashboard_cache': {'status': 'ready'}}, {'status': 'available'} ]
        def response(payload):
            return io.StringIO(json.dumps(payload))
        with patch('sync_job.urllib.request.urlopen', side_effect=[response(r) for r in responses]):
            self.assertTrue(job.ready('expected'))
        responses[1]['local_sync']['database'] = 'old'
        with patch('sync_job.urllib.request.urlopen', side_effect=[response(r) for r in responses]):
            self.assertFalse(job.ready('expected'))
