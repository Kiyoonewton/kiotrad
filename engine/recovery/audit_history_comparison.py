"""Audit a local replay JSON into reviewable run, daily and issue tables."""
import argparse
import csv
import hashlib
import json
from decimal import Decimal
from pathlib import Path

from comparison_audit import audit


def table(path, rows):
    if not rows:
        return
    with path.open('w', newline='') as stream:
        columns = list(rows[0])
        writer = csv.DictWriter(stream, fieldnames=columns)
        writer.writeheader()
        for row in rows:
            writer.writerow({k: json.dumps(v) if isinstance(v, (dict, list)) else v for k, v in row.items()})


def write_audit(source, destination):
    body = Path(source).read_bytes()
    result = audit(json.loads(body))
    result['source_sha256'] = hashlib.sha256(body).hexdigest()
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(result, indent=2) + '\n')
    for name in ('runs', 'daily', 'attempts', 'issues'):
        rows = []
        for row in result[name]:
            display = {k: v for k, v in row.items() if not k.endswith('_minor')}
            for key, value in row.items():
                if key.endswith('_minor'):
                    display[key.removesuffix('_minor') + '_NGN'] = f'{Decimal(value) / 100:.2f}' if value is not None else None
            rows.append(display)
        table(destination / (name + '.csv'), rows)
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('comparison')
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    try:
        report = write_audit(args.comparison, args.destination)
    except (OSError, ValueError, KeyError, TypeError, AttributeError, IndexError) as error:
        parser.exit(1, 'Comparison audit stopped: ' + str(error) + '\n')
    print('Ledger audit:', 'PASS' if report['passed'] else 'FAIL')
    for row in report['summaries']:
        print(row['scenario'], '| settled runs:', row['completed_runs'], '| net NGN:', f"{Decimal(row['net_minor']) / 100:.2f}")
    print('Issues:', len(report['issues']))
    print('Tables:', str(Path(args.destination) / 'runs.csv'), str(Path(args.destination) / 'daily.csv'))
    print('Arithmetic audit only; source accuracy and estimated kickoff uncertainty remain separate.')
    if not report['passed']:
        parser.exit(1)
