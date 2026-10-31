import http.client
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch
from http.server import ThreadingHTTPServer

import sync_agent
from sync_credentials import save


class SyncAgentTest(unittest.TestCase):
    def test_only_exact_dashboard_origin_host_and_ephemeral_token_can_start_parameterless_sync(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'engine/local').mkdir(parents=True)
            save(root, 'root@example.test', 'synthetic-secret')
            job = Mock()
            job.view.return_value = {'status': 'idle', 'job_id': None}
            job.start.side_effect = [True, False]
            server = ThreadingHTTPServer(('127.0.0.1', 0), sync_agent.handler(job))
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()

            def request(method, path, headers=None, body=None):
                connection = http.client.HTTPConnection('127.0.0.1', server.server_port)
                connection.request(method, path, body=body, headers={'Host': '127.0.0.1:5176',
                                   'Origin': 'http://localhost:5174', **(headers or {})})
                response = connection.getresponse()
                data = response.read()
                result = (response.status, dict(response.getheaders()), json.loads(data) if data else None)
                connection.close()
                return result

            try:
                with patch.object(sync_agent, 'ROOT', root):
                    status, headers, data = request('GET', '/status')
                    self.assertEqual(status, 200)
                    self.assertTrue(data['configured'])
                    self.assertNotIn('synthetic-secret', json.dumps(data))
                    self.assertEqual(headers['Access-Control-Allow-Origin'], 'http://localhost:5174')
                    token = data['token']
                    for bad in [{'Origin': 'https://evil.test'}, {'Origin': 'null'}, {'Host': 'evil.test:5176'}]:
                        code, response_headers, _ = request('GET', '/status', bad)
                        self.assertEqual(code, 403)
                        self.assertNotIn('Access-Control-Allow-Origin', response_headers)
                    self.assertEqual(request('POST', '/sync')[0], 403)
                    self.assertEqual(request('POST', '/sync', {'X-Kiotrad-Sync': token}, 'commands=bad')[0], 400)
                    job.start.assert_not_called()
                    self.assertEqual(request('POST', '/sync', {'X-Kiotrad-Sync': token})[0], 202)
                    self.assertEqual(request('POST', '/sync', {'X-Kiotrad-Sync': token})[0], 409)
                    self.assertEqual(request('OPTIONS', '/sync')[0], 204)
            finally:
                server.shutdown()
                server.server_close()
                thread.join()

    def test_helper_refuses_non_mac_before_serving_or_installing(self):
        with patch.object(sync_agent.sys, 'platform', 'linux'), patch.object(sync_agent.sys, 'argv', ['sync_agent.py']), \
             patch.object(sync_agent, 'ThreadingHTTPServer') as server:
            with self.assertRaises(RuntimeError):
                sync_agent.main()
            server.assert_not_called()
