#!/usr/bin/env python3
"""Mac-only loopback bridge. No remote shell, request-supplied commands or secrets."""
import argparse
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import secrets
import sys

from sync_credentials import required
from sync_job import SyncJob

ROOT = Path(__file__).resolve().parents[2]
ORIGINS = {'http://localhost:5174', 'http://127.0.0.1:5174'}
HOSTS = {'127.0.0.1:5176', 'localhost:5176'}


def handler(job):
    token = secrets.token_urlsafe(32)

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def allowed(self):
            return self.headers.get('Host') in HOSTS and self.headers.get('Origin') in ORIGINS

        def reply(self, code, data):
            body = json.dumps(data).encode()
            self.send_response(code)
            if self.allowed():
                self.send_header('Access-Control-Allow-Origin', self.headers['Origin'])
                self.send_header('Vary', 'Origin')
            self.send_header('Cache-Control', 'no-store')
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_OPTIONS(self):
            if not self.allowed():
                return self.reply(403, {'error': 'Local dashboard origin required.'})
            self.send_response(204)
            self.send_header('Access-Control-Allow-Origin', self.headers['Origin'])
            self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            self.send_header('Access-Control-Allow-Headers', 'X-Kiotrad-Sync')
            self.send_header('Access-Control-Allow-Private-Network', 'true')
            self.end_headers()

        def do_GET(self):
            if not self.allowed() or self.path != '/status':
                return self.reply(403, {'error': 'Local dashboard origin required.'})
            try:
                required(ROOT)
                configured = True
            except Exception:
                configured = False
            self.reply(200, {**job.view(), 'token': token, 'configured': configured})

        def do_POST(self):
            if not self.allowed() or self.path != '/sync' or not hmac.compare_digest(self.headers.get('X-Kiotrad-Sync', ''), token):
                return self.reply(403, {'error': 'Local sync authorization required.'})
            if self.headers.get('Content-Length', '0') != '0' or self.headers.get('Transfer-Encoding'):
                return self.reply(400, {'error': 'Sync accepts no parameters.'})
            try:
                started = job.start()
                self.reply(202 if started else 409, job.view())
            except Exception:
                self.reply(400, {'error': 'Host sync setup is missing or invalid. Run sync_agent.py --setup.'})

    return Handler


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--setup', action='store_true', help='One-time private credential setup and login helper installation')
    args = parser.parse_args()
    if sys.platform != 'darwin':
        raise RuntimeError('Local sync helper runs only on your Mac, never production.')
    if args.setup:
        from sync_agent_setup import setup
        setup(ROOT)
        return
    ThreadingHTTPServer(('127.0.0.1', 5176), handler(SyncJob(ROOT))).serve_forever()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('Local sync helper stopped. Check setup, port 5176 and local Docker/SSH access.', file=sys.stderr)
        sys.exit(1)
