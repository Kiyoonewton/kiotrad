"""Verify a staged history package without importing or modifying any evidence."""
import argparse
import hashlib
import json
import re
from pathlib import Path

from timing_audit import LEAGUES, ROUTE


def verify(folder):
    folder = Path(folder).resolve()
    manifest = json.loads((folder / 'manifest.json').read_text())
    if manifest.get('package_version') != 'legacy-history-package-1.0.0':
        raise ValueError('Unsupported or incomplete package')
    plan = (folder / 'plan.json').read_bytes()
    if hashlib.sha256(plan).hexdigest() != manifest.get('plan_sha256'):
        raise ValueError('Plan checksum differs')
    seen = set()
    for item in manifest['files']:
        digest = item['sha256']
        if not re.fullmatch(r'[a-f0-9]{64}', digest) or item['file'] != digest + '.json' or digest in seen:
            raise ValueError('Invalid filename or duplicate source hash')
        seen.add(digest)
        path = folder / 'files' / item['file']
        if path.is_symlink() or path.resolve().parent != folder / 'files' or path.stat().st_size > 20 * 1024 * 1024:
            raise ValueError('Unsafe package file')
        body = path.read_bytes()
        if hashlib.sha256(body).hexdigest() != digest:
            raise ValueError('Source checksum differs')
        payload = json.loads(body)
        route = ROUTE.fullmatch(str(payload.get('queryUrl', '')))
        if not route or route.group(1) != item['season_id'] or int(route.group(2)) != item['matchday']:
            raise ValueError('Route differs from manifest')
        events = payload['doc'][0]['data']['odds']
        leagues = {LEAGUES.get(str(e['tournament_id']).split(':')[-1]) for e in events}
        if leagues != {item['competition']} or not 1 <= item['matchday'] <= 30:
            raise ValueError('League or day differs from manifest')
    return {'verified_files': len(seen), 'applied': False,
            'notice': 'Package integrity verified; database fixture/price validation and timing policy still required.'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package')
    args = parser.parse_args()
    print(json.dumps(verify(args.package), indent=2))
