import copy
import hashlib
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from check_production_readiness import capture, write
from production_readiness_audit import audit
import test_comparison_audit


def snapshot():
    report = test_comparison_audit.ComparisonAuditTest().report()
    scenario = report['with_estimated_history']
    scenario['runs'][0]['estimated_timing_dependency'] = False
    scenario['qualification_first_win_day_min'] = 5
    scenario['preparation'] = {'status': 'ready'}
    dashboard = {'application': 'KioTrad', 'generated_at': '2026-11-01T09:42:58+00:00',
                 'dashboard_cache': {'status': 'ready', 'source': 'redis'}, 'shared_simulation': copy.deepcopy(scenario)}
    del dashboard['shared_simulation']['settings']
    return {'version': 'production-readiness-snapshot-1.0.0', 'database': 'kiotrad',
            'started_at': '2026-11-01T09:43:00+00:00', 'captured_at': '2026-11-01T09:43:20+00:00',
            'definition': {'capital_minor': 100000}, 'settings': scenario['settings'],
            'readiness': {'http_status': 200, 'data': {'status': 'ready', 'database': 'available'}},
            'overview': {'http_status': 200, 'data': dashboard}, 'overview_repeat': {'http_status': 200, 'data': copy.deepcopy(dashboard)},
            'scenario': {'http_status': 200, 'data': scenario}, 'scenario_repeat': {'http_status': 200, 'data': copy.deepcopy(scenario)},
            'last_good': {'dashboard': {'available': True}, 'prepared': {'available': True}},
            'imports': {'complete_packages': 1, 'verified_seasons': 2, 'failed_seasons': 0,
                        'new_matches': 240, 'new_prices': 240, 'expected_seasons': 2, 'expected_matches': 240, 'expected_prices': 240, 'unfinished_records': 0}}


class ProductionReadinessTest(unittest.TestCase):
    def test_ready_snapshot_audits_each_ledger_and_pins_local_report(self):
        source = snapshot()
        before = copy.deepcopy(source)
        report = audit(source)
        self.assertTrue(report['passed'], report['issues'])
        self.assertEqual(len(report['summaries']), 4)
        self.assertEqual(report['daily'][0]['date_wat'], '2026-10-18')
        self.assertEqual(source, before)
        with tempfile.TemporaryDirectory() as tmp:
            body = json.dumps(source).encode()
            folder = Path(tmp) / 'review'
            result = write(body, folder)
            self.assertEqual(result['snapshot_sha256'], hashlib.sha256(body).hexdigest())
            self.assertEqual((folder / 'snapshot.json').read_bytes(), body)
            self.assertTrue((folder / 'daily.csv').is_file())
            with self.assertRaises(FileExistsError):
                write(body, folder)

    def test_not_ready_stale_cache_missing_backup_and_import_mismatch_fail(self):
        mutations = [
            lambda s: s['readiness'].update(http_status=503),
            lambda s: s['overview']['data']['dashboard_cache'].update(status='stale'),
            lambda s: s['overview']['data'].update(generated_at='2026-10-31T00:00:00Z'),
            lambda s: s['scenario'].update(http_status=202, data={'status': 'warming'}),
            lambda s: s['last_good']['prepared'].update(available=False),
            lambda s: s['imports'].update(failed_seasons=1),
            lambda s: s['imports'].update(unfinished_records=1),
            lambda s: s['imports'].update(complete_packages=0),
            lambda s: s['imports'].update(verified_seasons=1),
            lambda s: s['scenario']['data']['preparation'].update(status='failed'),
        ]
        for mutate in mutations:
            with self.subTest(mutation=mutate):
                source = snapshot()
                mutate(source)
                self.assertFalse(audit(source)['passed'])

    def test_bad_totals_and_changed_capital_are_not_accepted(self):
        source = snapshot()
        source['scenario']['data']['runs'][0]['gross_payout_minor'] += 1
        source['overview_repeat']['data']['shared_simulation']['starting_capital_minor'] += 100
        result = audit(source)
        self.assertFalse(result['passed'])
        self.assertTrue({'run_payout_sum', 'starting_capital_settings'} <= {i['code'] for i in result['issues']})

    def test_different_valid_evidence_cutoffs_are_not_forced_to_match(self):
        source = snapshot()
        view = source['scenario_repeat']['data']
        view.update(runs=[], equity=[], balance_minor=100000)
        self.assertTrue(audit(source)['passed'])

    def test_ssh_input_and_remote_errors_are_safe(self):
        with patch('check_production_readiness.subprocess.run') as run:
            with self.assertRaises(ValueError):
                capture('-oProxyCommand=unsafe')
            run.assert_not_called()
            run.return_value = subprocess.CompletedProcess([], 1, b'sensitive failure', b'password')
            with self.assertRaisesRegex(RuntimeError, 'Credentials hidden') as raised:
                capture('example-host')
            self.assertNotIn('sensitive', str(raised.exception))
            self.assertIn('BatchMode=yes', run.call_args.args[0])
            self.assertEqual(run.call_args.kwargs['stderr'], subprocess.DEVNULL)
            run.return_value = subprocess.CompletedProcess([], 0, b'not json', b'')
            with self.assertRaisesRegex(RuntimeError, 'invalid diagnostic'):
                capture('example-host')
