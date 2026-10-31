"""Install the current user's loopback helper; credentials stay out of launchd."""
from getpass import getpass
import os
from pathlib import Path
import plistlib
import subprocess
import sys
import json
import urllib.request
import urllib.error

from sync_credentials import save

LABEL = 'com.kiotrad.local-sync'


def setup(root):
    try:
        request = urllib.request.Request('http://127.0.0.1:5176/status', headers={'Origin': 'http://localhost:5174'})
        with urllib.request.urlopen(request, timeout=2) as response:
            if json.load(response).get('status') in ('running', 'preparing'):
                raise RuntimeError('Wait for the current local sync before running setup again.')
    except urllib.error.URLError:
        pass
    server = input('Production SSH alias/user@host [root@169.58.227.92]: ').strip() or 'root@169.58.227.92'
    save(root, server, getpass('Production kiotrad_dev read-only password (saved privately on this Mac): '))
    folder = Path.home() / 'Library/LaunchAgents'
    folder.mkdir(parents=True, exist_ok=True)
    path = folder / (LABEL + '.plist')
    if path.is_symlink():
        raise RuntimeError('LaunchAgent configuration may not be a symlink.')
    logs = Path(root) / 'recovery/local-sync-agent'
    logs.mkdir(parents=True, exist_ok=True)
    environment = {'PATH': os.environ.get('PATH', '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin')}
    if os.environ.get('SSH_AUTH_SOCK'):
        environment['SSH_AUTH_SOCK'] = os.environ['SSH_AUTH_SOCK']
    payload = {'Label': LABEL, 'ProgramArguments': [sys.executable, str(Path(root) / 'engine/local/sync_agent.py')],
               'WorkingDirectory': str(root), 'EnvironmentVariables': environment,
               'RunAtLoad': True, 'KeepAlive': True,
               'StandardOutPath': str(logs / 'helper.log'), 'StandardErrorPath': str(logs / 'helper.log')}
    # Reconfiguration is an explicit setup action; do not restart during a sync.
    domain = 'gui/' + str(os.getuid())
    subprocess.run(['launchctl', 'bootout', domain + '/' + LABEL], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    path.write_bytes(plistlib.dumps(payload))
    path.chmod(0o600)
    subprocess.run(['launchctl', 'bootstrap', domain, str(path)], check=True)
    print('Local sync helper enabled. Open http://localhost:5174 and click Sync prod → local.')
