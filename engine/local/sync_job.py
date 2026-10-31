"""One host-side pull job with sanitized progress and explicit HTTP readiness."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import threading
import time
import urllib.request
from urllib.parse import urlencode
import uuid

from sync_credentials import required


class SyncJob:
    def __init__(self, root):
        self.root = Path(root)
        self.lock = threading.Lock()
        self.state = {'status': 'idle', 'phase': 'Ready to sync', 'job_id': None}
        self.started = None

    def view(self):
        with self.lock:
            return {**self.state, 'elapsed_seconds': self.state.get('elapsed_seconds', int(time.monotonic() - self.started) if self.started else 0)}

    def update(self, **values):
        with self.lock:
            self.state.update(values)
            if values.get('status') in ('ready', 'failed'):
                self.state['elapsed_seconds'] = int(time.monotonic() - self.started) if self.started else 0

    def start(self):
        credentials = required(self.root)
        with self.lock:
            if self.state['status'] in ('running', 'preparing'):
                return False
            self.started = time.monotonic()
            self.state = {'status': 'running', 'phase': 'Starting sync', 'job_id': uuid.uuid4().hex,
                          'done': None, 'total': None, 'error': None}
        threading.Thread(target=self.run, args=(credentials,), daemon=True).start()
        return True

    def progress(self, line):
        phases = [('Downloading strategy', 'Reading production settings'),
                  ('Preparing a fixed raw-file', 'Checking raw evidence inventory'),
                  ('Reusing ', 'Downloading new or changed evidence'),
                  ('Validated ', 'Rebuilding local views'),
                  ('Replaying retained history', 'Replaying retained local history'),
                  ('Verifying retained fixture', 'Verifying retained history'),
                  ('Validating staging', 'Validating the new snapshot'),
                  ('Preparing staging dashboard', 'Preparing dashboard and Simulator'),
                  ('Local snapshot ready:', 'Checking dashboard readiness'),
                  ('Local retention:', 'Cleaning older local snapshots')]
        for prefix, phase in phases:
            if line.startswith(prefix):
                self.update(phase=phase, done=None, total=None)
        inventory = re.match(r'Reusing (\d+) verified local raw files; downloading (\d+) new or changed files', line)
        if inventory:
            self.update(phase='Reusing ' + inventory[1] + ' files; downloading ' + inventory[2] + ' new or changed files')
        progress = re.search(r'workbench: (fixture_baselines|season_monitoring|run_evidence) (\d+)/(\d+)', line)
        if progress:
            self.update(phase={'fixture_baselines': 'Preparing historical prices', 'season_monitoring': 'Checking seasons',
                               'run_evidence': 'Preparing simulation runs'}[progress[1]], done=int(progress[2]), total=int(progress[3]))
        # Only publish allowlisted counters, never arbitrary child logs or errors.
        try:
            data = json.loads(line)
        except ValueError:
            return
        if isinstance(data, dict) and re.fullmatch(r'\d+/\d+', str(data.get('progress', ''))):
            done, total = map(int, data['progress'].split('/'))
            self.update(phase='Replaying retained local history', done=done, total=total)

    def run(self, credentials):
        try:
            previous = self.active_database()
            env = {**os.environ, **credentials, 'KIOTRAD_SYNC_NONINTERACTIVE': '1', 'PYTHONUNBUFFERED': '1'}
            with subprocess.Popen([sys.executable, '-u', str(self.root / 'engine/local/sync.py'),
                                   '--ssh', credentials['KIOTRAD_SYNC_SSH']], cwd=self.root,
                                  env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                  stderr=subprocess.STDOUT, text=True) as process:
                for line in process.stdout:
                    self.progress(line.strip())
                if process.wait() != 0:
                    raise RuntimeError('sync_failed')
            self.update(status='preparing', phase='Checking dashboard and Simulator readiness', done=None, total=None)
            database = self.active_database()
            if not database or database == previous:
                raise RuntimeError('snapshot_missing')
            deadline = time.monotonic() + 180
            while time.monotonic() < deadline:
                if self.ready(database):
                    self.update(status='ready', phase='Dashboard ready to view', database=database,
                                url='http://localhost:5174', done=None, total=None)
                    return
                time.sleep(2)
            raise RuntimeError('readiness_not_confirmed')
        except Exception:
            failed_phase = self.view().get('phase', 'Starting sync')
            self.update(status='failed', phase='Sync needs attention', done=None, total=None,
                        error='Sync or readiness verification failed during: ' + failed_phase + '. Run the terminal sync command for diagnostics; no credentials are shown here.')

    def active_database(self):
        for line in (self.root / 'engine/local/.env.local').read_text().splitlines():
            if line.startswith('LOCAL_DATABASE='):
                value = line.split('=', 1)[1]
                return value if re.fullmatch(r'kiotrad_local_[0-9TZ]+', value) else None
        return None

    def ready(self, database):
        def get(path):
            with urllib.request.urlopen('http://127.0.0.1:5174/api/v1/' + path, timeout=5) as response:
                return json.load(response)
        try:
            if get('ready').get('status') != 'ready':
                return False
            overview = get('overview')
            if (overview.get('local_sync') or {}).get('database') != database or overview.get('dashboard_cache', {}).get('status') not in ('ready', 'stale'):
                return False
            scenario = get('research/scenario?' + urlencode({'capital': 50000, 'qualification': 5,
                           'attempts': 4, 'allowance': 1, 'strategy': 'all', 'league': 'all'}))
            return scenario.get('status') == 'available'
        except Exception:
            return False
