"""Capture production readiness over SSH and save local review tables. No apply mode."""
import argparse
import hashlib
import json
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
import subprocess

from audit_history_comparison import table
from plan_production_history import ROOT, ssh
from production_readiness_audit import audit


def capture(server):
    script = (Path(__file__).with_name('production_readiness_snapshot.php')).read_bytes()
    try:
        result = subprocess.run(ssh(server, ['docker', 'exec', '-i', 'kiotrad-api-1',
                                            'php', '-d', 'memory_limit=512M']),
                                input=script, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=900)
    except (OSError, subprocess.TimeoutExpired):
        raise RuntimeError('Production capture failed or timed out; credentials hidden.') from None
    if result.returncode:
        raise RuntimeError('Production capture failed; check production services/version. Credentials hidden.')
    try:
        snapshot = json.loads(result.stdout)
        if snapshot.get('version') != 'production-readiness-snapshot-1.0.0':
            raise ValueError()
    except (ValueError, AttributeError):
        raise RuntimeError('Production returned an invalid diagnostic response; credentials hidden.') from None
    return result.stdout


def write(body, destination, max_age=180):
    snapshot = json.loads(body)
    report = audit(snapshot, max_age)
    report['snapshot_sha256'] = hashlib.sha256(body).hexdigest()
    report['collector_sha256'] = hashlib.sha256(Path(__file__).with_name('production_readiness_snapshot.php').read_bytes()).hexdigest()
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'snapshot.json').write_bytes(body)
    (destination / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    for name in ('runs', 'daily', 'issues'):
        table(destination / (name + '.csv'), report[name])
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument('--ssh', help='Existing production SSH alias or user@host')
    source.add_argument('--snapshot', help='Re-audit an already captured local snapshot')
    parser.add_argument('--destination', default=str(ROOT / 'recovery' / ('production-readiness-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'))))
    parser.add_argument('--max-age-seconds', type=int, default=180)
    args = parser.parse_args()
    try:
        if Path(args.destination).exists():
            raise ValueError('Use a new destination; previous reports are retained.')
        print('Capturing production readiness and retained import integrity...' if args.ssh else 'Auditing saved production snapshot...', flush=True)
        body = capture(args.ssh) if args.ssh else Path(args.snapshot).read_bytes()
        report = write(body, args.destination, args.max_age_seconds)
    except (OSError, RuntimeError, ValueError, KeyError, TypeError, AttributeError, IndexError) as error:
        parser.exit(1, 'Production readiness check stopped: ' + str(error) + '\n')
    print('Production readiness:', 'PASS' if report['passed'] else 'FAIL')
    print('Database:', report['database'])
    print('Production settings:', report['settings'])
    print('Import integrity:', report['imports'])
    for row in report['summaries']:
        print(row['scenario'], '| completed:', row['completed_runs'], '| balance NGN:', f"{Decimal(row['ending_balance_minor']) / 100:.2f}")
    print('Issues:', len(report['issues']))
    print('Reports:', args.destination)
    print('No production evidence, settings or ledger writes. Modeled timing unchanged.')
    if not report['passed']:
        parser.exit(1)
