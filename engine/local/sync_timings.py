"""Local phase timings only: never persist commands, credentials or exception text."""
from contextlib import contextmanager
from datetime import datetime, timezone
import json
from pathlib import Path
import time

STAGES = {
    'startup': 'Starting local services', 'settings': 'Reading production settings',
    'inventory': 'Reading production raw inventory', 'reuse': 'Checking reusable raw files',
    'download': 'Downloading new evidence', 'verify': 'Verifying raw files',
    'build': 'Preparing API image', 'parser': 'Checking parser compatibility',
    'seed': 'Copying previous local snapshot', 'rebuild': 'Processing raw evidence',
    'blockages': 'Copying blockage evidence', 'packages': 'Recovering history packages',
    'history': 'Replaying and verifying history', 'validate': 'Validating staging data',
    'views': 'Preparing dashboard views', 'activate': 'Activating snapshot',
    'retention': 'Cleaning older local snapshots',
}


class SyncTimings:
    def __init__(self, root, clock=time.monotonic):
        self.clock = clock
        self.started = self.phase_started = clock()
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
        self.path = Path(root) / 'recovery/sync-timings' / (stamp + '.json')
        self.phase = None
        self.data = {'version': 'local-sync-timings-1.0.0', 'status': 'running',
                     'started_at': datetime.now(timezone.utc).isoformat(), 'stages': [], 'operations': [], 'evidence': {}}

    def save(self):
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            temporary = self.path.with_suffix('.tmp')
            temporary.write_text(json.dumps(self.data, indent=2) + '\n')
            temporary.replace(self.path)
        except OSError:
            print('Sync timing report could not be saved; sync safety checks remain enabled.', flush=True)

    def stage(self, name):
        if name not in STAGES:
            raise ValueError('Unknown timing stage.')
        self.end_phase('complete')
        self.phase, self.phase_started = name, self.clock()
        self.data['current_stage'] = name
        self.save()
        print('Sync phase: ' + STAGES[name], flush=True)

    def end_phase(self, status):
        if self.phase is not None:
            elapsed = round(max(0, self.clock() - self.phase_started), 3)
            self.data['stages'].append({'stage': self.phase, 'seconds': elapsed, 'status': status})
            self.phase = None

    def evidence(self, **values):
        for key, value in values.items():
            if key in ('raw_files', 'reused_files', 'download_files', 'download_bytes', 'archive_bytes') and type(value) is int:
                self.data['evidence'][key] = value
            elif key == 'seed' and isinstance(value, dict) and value.get('mode') in ('full_rebuild', 'incremental_seed') and value.get('reason') in (
                    'explicit_request', 'no_eligible_seed', 'missing_completed_index', 'parser_changed', 'compatible_completed_index'):
                self.data['evidence'][key] = {k: value[k] for k in ('mode', 'reason') if k in value}
        self.save()

    @contextmanager
    def operation(self, name):
        if name not in ('history_import', 'history_verification', 'raw_transfer', 'raw_extraction_validation'):
            raise ValueError('Unknown timing operation.')
        start, status = self.clock(), 'failed'
        try:
            yield
            status = 'complete'
        finally:
            self.data['operations'].append({'operation': name, 'seconds': round(max(0, self.clock() - start), 3), 'status': status})
            self.save()

    def __enter__(self):
        self.stage('startup')
        return self

    def __exit__(self, kind, error, traceback):
        self.end_phase('failed' if kind else 'complete')
        self.data.update(status='failed' if kind else 'complete', elapsed_seconds=round(max(0, self.clock() - self.started), 3))
        self.save()
        print('Sync timing report: ' + str(self.path), flush=True)
        for stage in sorted(self.data['stages'], key=lambda row: row['seconds'], reverse=True)[:5]:
            print(f"Sync timing: {STAGES[stage['stage']]} · {stage['seconds']:.1f}s", flush=True)
        labels = {'raw_transfer': 'Network/archive transfer', 'raw_extraction_validation': 'Local extraction and validation'}
        for operation in self.data['operations']:
            if operation['operation'] in labels:
                print(f"Raw download timing: {labels[operation['operation']]} · {operation['seconds']:.1f}s ({operation['status']})", flush=True)
