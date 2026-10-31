"""Host-only sync credentials; never sourced as shell code or sent to the browser."""
import json
import os
from pathlib import Path
import re

KEYS = ('KIOTRAD_SYNC_SSH', 'KIOTRAD_SYNC_PASSWORD')


def settings(root):
    values = {}
    path = Path(root) / 'engine/local/.env.sync'
    if path.exists():
        if path.is_symlink() or path.stat().st_mode & 0o077:
            raise RuntimeError('Host sync env must be a regular private file (chmod 600 engine/local/.env.sync).')
        for line in path.read_text().splitlines():
            key, separator, value = line.partition('=')
            if separator and key in KEYS:
                try:
                    values[key] = json.loads(value) if value.startswith('"') else value
                except ValueError:
                    raise RuntimeError('Invalid host sync env; run sync_agent.py --setup again.') from None
    values.update({key: os.environ[key] for key in KEYS if key in os.environ})
    if any(not isinstance(value, str) for value in values.values()):
        raise RuntimeError('Host sync env values must be strings.')
    return values


def required(root):
    values = settings(root)
    server = values.get('KIOTRAD_SYNC_SSH', '')
    if not re.fullmatch(r'[A-Za-z0-9_.@-]+', server) or server.startswith('-') or not values.get('KIOTRAD_SYNC_PASSWORD'):
        raise RuntimeError('Configure host sync credentials with python3 engine/local/sync_agent.py --setup.')
    return values


def save(root, server, password):
    if not password or not re.fullmatch(r'[A-Za-z0-9_.@-]+', server) or server.startswith('-'):
        raise RuntimeError('Supply an SSH alias/user@host and nonempty read-only password.')
    path = Path(root) / 'engine/local/.env.sync'
    if path.is_symlink():
        raise RuntimeError('Host sync env may not be a symlink.')
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.fchmod(descriptor, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(''.join(key + '=' + json.dumps(value) + '\n' for key, value in zip(KEYS, [server, password])))
