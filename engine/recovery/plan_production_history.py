"""Mac-only read-only production history inventory and review plan; no apply mode."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
from urllib.parse import quote

from audit_history_comparison import table
from legacy_source_evidence import SourcePackage
from production_history_plan import assess_season, final_plan, strategy_alignment

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'engine/local'))
from sync_credentials import required  # noqa: E402


def ssh(server, remote):
    if not re.fullmatch(r'[A-Za-z0-9_.@-]+', server) or server.startswith('-'):
        raise ValueError('Use an SSH alias or user@host, without extra arguments.')
    return ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=3', server, shlex.join(remote)]


def validate_inputs(comparison, ledger, sources, package):
    body = Path(comparison).read_bytes()
    result = json.loads(body)
    digest = hashlib.sha256(body).hexdigest()
    checked = json.loads(Path(ledger).read_bytes())
    proof = json.loads(Path(sources).read_bytes())
    if result.get('version') != 'estimated-history-comparison-1.0.0' or result.get('benchmark_eligible') is not False or result.get('research_only') is not True:
        raise ValueError('Use the reviewed research comparison report.')
    if checked.get('version') != 'comparison-ledger-audit-1.0.0' or checked.get('passed') is not True or checked.get('source_sha256') != digest:
        raise ValueError('Ledger audit is missing, failed or refers to a different comparison.')
    if proof.get('version') != 'added-run-source-audit-1.0.0' or proof.get('passed') is not True or proof.get('comparison_sha256') != digest:
        raise ValueError('Source audit is missing, failed or refers to a different comparison.')
    selected = set(result['difference']['accepted_only_with_estimates'])
    if checked.get('issues') != [] or proof.get('issues') != [] or {r['run_id'] for r in proof.get('runs', [])} != selected or any(r.get('passed') is not True for r in proof.get('runs', [])):
        raise ValueError('Audit issues or added-run coverage are incomplete.')
    if proof.get('manifest_sha256') != package.manifest_hash:
        raise ValueError('Source audit refers to a different retained package.')
    return result, {'comparison_sha256': digest, 'ledger_audit_sha256': hashlib.sha256(Path(ledger).read_bytes()).hexdigest(),
                    'source_audit_sha256': hashlib.sha256(Path(sources).read_bytes()).hexdigest(),
                    'manifest_sha256': package.manifest_hash}


def current_code():
    files = [*ROOT.glob('apps/api/app/Domain/Strategies/*.php'), *ROOT.glob('apps/api/app/Domain/Research/*.php'),
             ROOT / 'apps/api/app/Domain/Paper/RecoveryStake.php']
    hashes = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
    source = (ROOT / 'apps/api/app/Domain/Strategies/RunReplay.php').read_text()
    version = re.search(r"const EVENT_VERSION = '([^']+)';", source)
    if version is None:
        raise ValueError('Local replay version could not be established.')
    return hashes, version.group(1)


def production_snapshot(server):
    script = b'''<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
echo json_encode(app(App\\Domain\\Strategies\\SimulationDefinitionSnapshot::class)->current(), JSON_THROW_ON_ERROR);
'''
    response = subprocess.run(ssh(server, ['docker', 'exec', '-i', 'kiotrad-api-1', 'php']),
                              input=script, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    if response.returncode:
        raise RuntimeError('Production strategy read failed; credentials hidden.')
    try:
        return json.loads(response.stdout)
    except ValueError:
        raise RuntimeError('Production strategy response was invalid; credentials hidden.') from None


def inventory(server, password, targets, destination, on_season):
    request_hash = hashlib.sha256(json.dumps(targets, sort_keys=True).encode()).hexdigest()
    uri = 'mongodb://kiotrad_dev:' + quote(password, safe='') + '@127.0.0.1:27017/kiotrad?authSource=kiotrad'
    request = {'uri': uri, 'targets': targets, 'sha256': request_hash}
    script = 'const REQUEST = ' + json.dumps(request) + ';\n' + Path(__file__).with_name('production_history_inventory.js').read_text()
    command = ssh(server, ['docker', 'exec', '-i', 'kiotrad-mongodb-1', 'mongosh', '--quiet', '--nodb', '--file', '/dev/stdin'])
    header = complete = None
    wanted = {identity for target in targets for identity in target['ids']}
    seen = {'fixtures': set(), 'prices': set()}
    records, phase = {}, 'fixtures'
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        # mongosh consumes the script from /dev/stdin before executing it.
        process.stdin.write(script.encode())
        process.stdin.close()
        with (destination / 'production-inventory.jsonl').open('w') as output:
            for line in process.stdout:
                try:
                    row = json.loads(line)
                except ValueError:
                    raise RuntimeError('Unexpected production response; credentials hidden.') from None
                kind = row.get('type')
                if kind == 'error':
                    raise RuntimeError('Production read-only inventory failed; check SSH and saved read-only credentials.')
                if kind == 'start':
                    if header is not None or row.get('database') != 'kiotrad' or row.get('read_only_role_verified') is not True or row.get('request_sha256') != request_hash or row.get('seasons') != len(targets) or row.get('target_fixtures') != len(wanted):
                        raise ValueError('Invalid production read-only inventory header.')
                    header = row
                    print('Read-only production role verified. Grouping fixture evidence at cutoff ' + row.get('cutoff', 'unknown'), flush=True)
                elif kind in ('fixtures', 'prices'):
                    identity = row['id']
                    if header is None or complete is not None or phase != kind or identity not in wanted or identity in seen[kind]:
                        raise ValueError('Unexpected, duplicate or out-of-order fixture evidence.')
                    seen[kind].add(identity)
                    stored = records.setdefault(identity, {'fixtures': [], 'prices': [], 'invalid_quotes': 0})
                    stored[kind] = row['variants']
                    if kind == 'prices':
                        stored['invalid_quotes'] = row['invalid_quotes']
                    if len(seen[kind]) % 1000 == 0:
                        print(f"Production {kind}: {len(seen[kind])} groups read", flush=True)
                elif kind == 'phase_complete':
                    if header is None or phase not in seen or row.get('phase') != phase or row.get('records') != len(seen[phase]):
                        raise ValueError('Incomplete production evidence phase.')
                    print(f"Production {phase} phase complete: {len(seen[phase])} distinct fixture groups", flush=True)
                    phase = 'prices' if phase == 'fixtures' else 'done'
                    if phase == 'prices':
                        print('Grouping production quote evidence...', flush=True)
                elif kind == 'complete':
                    if complete is not None or header is None or phase != 'done' or row.get('applied') is not False or row.get('seasons') != len(targets) or row.get('request_sha256') != request_hash or any(row.get(k) != len(seen[k]) for k in seen):
                        raise ValueError('Invalid production inventory completion marker.')
                    complete = row
                else:
                    raise ValueError('Unknown production inventory response.')
                output.write(json.dumps(row) + '\n')
                output.flush()
        if process.wait() != 0 or header is None or complete is None:
            raise RuntimeError('Production inventory did not complete; no final plan was published.')
        for position, target in enumerate(targets, 1):
            on_season({'league': target['league'], 'season': target['season'], 'progress': position,
                       'matches': {identity: records[identity] for identity in target['ids'] if identity in records}})
    finally:
        if process.poll() is None:
            process.kill()
        process.wait()
    return {'inventory_header': header, 'inventory_completion': complete,
            'inventory_sha256': hashlib.sha256((destination / 'production-inventory.jsonl').read_bytes()).hexdigest()}


def prepare(args):
    if sys.platform != 'darwin':
        raise RuntimeError('Run this read-only production planner on the Mac, not on the server.')
    credentials = required(ROOT)
    server = args.ssh or credentials['KIOTRAD_SYNC_SSH']
    package = SourcePackage(args.package, lambda league, season, count: print(f'Package evidence: {league}:{season} | {count} files', flush=True))
    comparison, provenance = validate_inputs(args.comparison, args.ledger_audit, args.source_audit, package)
    destination = Path(args.destination).resolve()
    if destination.exists():
        raise FileExistsError('Choose a new production plan output folder.')
    if destination == package.root or package.root in destination.parents:
        raise ValueError('Keep output outside the retained package.')
    sources, targets, identities = {}, [], {}
    for key in sorted(package.index):
        matches = package.season(*key)
        for identity, match in matches.items():
            fingerprint = (*key, match['day'], match['home_uid'], match['away_uid'])
            if identity in identities and identities[identity] != fingerprint:
                raise ValueError('Conflicting source match identity across package seasons.')
            identities[identity] = fingerprint
        sources[key] = matches
        targets.append({'league': key[0], 'season': key[1], 'ids': sorted(matches)})
    destination.mkdir(parents=True, exist_ok=False)
    print('Reading production strategy definitions...', flush=True)
    snapshot = production_snapshot(server)
    (destination / 'production-strategy.json').write_text(json.dumps(snapshot, indent=2) + '\n')
    hashes, version = current_code()
    alignment = strategy_alignment(snapshot, hashes, version, comparison['settings']['capital'] * 100)
    summaries, fixtures = [], []

    def assess(row):
        key = row['league'], row['season']
        summary, detail = assess_season(*key, sources[key], row['matches'])
        summaries.append(summary)
        fixtures.extend(detail)
        print(f"Production comparison: {row['progress']}/{len(targets)} | {key[0]}:{key[1]}", flush=True)

    provenance.update(inventory(server, credentials['KIOTRAD_SYNC_PASSWORD'], targets, destination, assess))
    after = production_snapshot(server)
    if after != snapshot:
        raise RuntimeError('Production strategy/settings changed during audit; no final plan published.')
    provenance['verified_package_files'] = len(package.read_hashes)
    provenance['local_code_hashes'] = hashes
    provenance['production_strategy_sha256'] = hashlib.sha256(json.dumps(snapshot, sort_keys=True).encode()).hexdigest()
    provenance['reviewed_settings'] = comparison['settings']
    result = final_plan(summaries, fixtures, alignment, provenance)
    table(destination / 'seasons.csv', summaries)
    table(destination / 'fixtures.csv', fixtures)
    (destination / 'report.json').write_text(json.dumps(result, indent=2) + '\n')
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', required=True)
    parser.add_argument('--comparison', required=True)
    parser.add_argument('--ledger-audit', required=True)
    parser.add_argument('--source-audit', required=True)
    parser.add_argument('--destination', required=True)
    parser.add_argument('--ssh')
    args = parser.parse_args()
    os.umask(0o077)
    try:
        result = prepare(args)
    except (OSError, ValueError, KeyError, TypeError, AttributeError, IndexError, RuntimeError) as error:
        parser.exit(1, 'Production plan stopped: ' + str(error) + '\n')
    print('Production read-only plan complete. Seasons:', len(result['seasons']))
    print('Blocked seasons:', len(result['blocked_seasons']))
    print('Fixture actions:', result['fixture_action_counts'])
    print('Price actions:', result['price_action_counts'])
    print('Strategy alignment:', result['strategy_alignment'])
    print('Review folder:', args.destination)
    print('No production writes or estimated dates applied. Review and tested backups are still required before a future import.')
