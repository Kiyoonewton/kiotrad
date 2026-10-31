import copy
import json
import tempfile
import unittest
from pathlib import Path

from audit_history_comparison import write_audit
from comparison_audit import audit


class ComparisonAuditTest(unittest.TestCase):
    def report(self):
        settings = {'capital': 1000, 'capital_mode': 'compound', 'profit_stop': 0, 'qualification': 5,
                    'attempts': 4, 'allowance': 1, 'strategy': 'all', 'league': 'all'}
        run = {'id': 'euro:100:next-win', 'competition': 'virtual-euro', 'season_id': '100',
               'strategy_id': 'champion-next-win', 'team': 'Example', 'status': 'win',
               'estimated_timing_dependency': True, 'entry_at': '2026-10-17T22:59:00Z',
               'released_at': '2026-10-17T23:02:21Z', 'capital_at_entry_minor': 100000,
               'sizing_capital_at_entry_minor': 100000, 'total_staked_minor': 10000,
               'gross_payout_minor': 20000, 'net_minor': 10000,
               'estimate': {'allowance_minor': 10000, 'plan': {'profit_per_attempt_minor': 10000}},
               'attempts': [{'attempt': 1, 'stake_minor': 10000, 'actual_odds_scaled': 20000,
                             'target_profit_minor': 10000, 'won': True, 'payout_minor': 20000}]}
        baseline = {'status': 'available', 'settings': settings, 'starting_capital_minor': 100000,
                    'max_attempts': 4, 'allowance_multiplier': 1, 'capital_mode': 'compound', 'profit_stop_minor': 0,
                    'as_of': '2026-10-31T00:00:00Z', 'runs': [], 'equity': [], 'active_run_id': None, 'balance_minor': 100000}
        hypothesis = {**baseline, 'runs': [run], 'equity': [{'run_id': run['id'], 'at': run['released_at'], 'balance_minor': 110000}],
                      'balance_minor': 110000}
        return {'version': 'estimated-history-comparison-1.0.0', 'research_only': True, 'benchmark_eligible': False,
                'settings': settings, 'recorded_only': baseline, 'with_estimated_history': hypothesis,
                'difference': {'balance_minor': 10000, 'accepted_only_with_estimates': [run['id']], 'accepted_only_recorded': []}}

    def codes(self, report):
        return {i['code'] for i in audit(report)['issues']}

    def test_totals_reconcile_and_cross_midnight_stakes_belong_to_release_day_in_lagos(self):
        report = self.report()
        before = copy.deepcopy(report)
        result = audit(report)
        self.assertTrue(result['passed'])
        self.assertEqual(result['daily'], [{'scenario': 'with_estimated_history', 'date_wat': '2026-10-18',
                                          'settled_runs': 1, 'estimated_dependency_runs': 1,
                                          'stake_minor': 10000, 'payout_minor': 20000, 'net_minor': 10000}])
        self.assertEqual(report, before)

    def test_corrupt_payout_run_net_and_equity_are_detected_independently(self):
        report = self.report()
        view = report['with_estimated_history']
        view['runs'][0]['attempts'][0]['payout_minor'] += 1
        view['runs'][0]['net_minor'] += 2
        view['equity'][0]['balance_minor'] += 3
        codes = self.codes(report)
        self.assertTrue({'attempt_payout', 'run_payout_sum', 'run_net', 'equity_balance', 'ending_balance'} <= codes)

    def test_stake_recovery_rounding_is_checked_with_integer_math(self):
        report = self.report()
        report['with_estimated_history']['runs'][0]['attempts'][0]['stake_minor'] = 10100
        self.assertIn('stake_recovery_calculation', self.codes(report))

    def test_duplicate_run_and_accepted_overlap_are_reported(self):
        report = self.report()
        run = report['with_estimated_history']['runs'][0]
        report['with_estimated_history']['runs'].append(copy.deepcopy(run))
        codes = self.codes(report)
        self.assertIn('duplicate_run_id', codes)
        self.assertIn('accepted_windows_overlap', codes)

    def test_pending_exposure_is_not_booked_as_daily_profit(self):
        report = self.report()
        view = report['with_estimated_history']
        run = view['runs'][0]
        run.update(status='awaiting_result', released_at=None, net_minor=None, gross_payout_minor=0)
        run['attempts'][0].update(payout_minor=None, won=None)
        view.update(equity=[], balance_minor=100000, active_run_id=run['id'])
        report['difference'].update(balance_minor=0, accepted_only_with_estimates=[])
        result = audit(report)
        self.assertTrue(result['passed'])
        self.assertEqual(result['daily'], [])
        self.assertEqual(result['summaries'][1]['pending_run_id'], run['id'])

    def test_skipped_rows_are_not_funded_or_booked(self):
        report = self.report()
        skipped = copy.deepcopy(report['with_estimated_history']['runs'][0])
        skipped.update(id='skipped', status='skipped_overlap', blocked_by='euro:100:next-win')
        skipped.pop('attempts')
        report['with_estimated_history']['runs'].append(skipped)
        self.assertTrue(audit(report)['passed'])

    def test_reported_difference_and_accepted_ids_must_match_the_ledger(self):
        report = self.report()
        report['difference'].update(balance_minor=1, accepted_only_with_estimates=[])
        self.assertTrue({'reported_balance_difference', 'reported_accepted_only_with_estimates'} <= self.codes(report))

    def test_float_money_truncated_report_and_naive_times_never_pass(self):
        report = self.report()
        report['with_estimated_history']['balance_minor'] = 110000.0
        with self.assertRaises(ValueError):
            audit(report)
        report = self.report()
        del report['with_estimated_history']['equity']
        with self.assertRaises(KeyError):
            audit(report)
        report = self.report()
        report['with_estimated_history']['runs'][0]['entry_at'] = '2026-10-17T22:59:00'
        with self.assertRaises(ValueError):
            audit(report)

    def test_loss_recovery_before_win_reconciles_all_stakes_and_payouts(self):
        report = self.report()
        view = report['with_estimated_history']
        run = view['runs'][0]
        first = {**run['attempts'][0], 'won': False, 'payout_minor': 0}
        second = {**run['attempts'][0], 'attempt': 2, 'stake_minor': 30000,
                  'target_profit_minor': 20000, 'payout_minor': 60000}
        run.update(attempts=[first, second], total_staked_minor=40000, gross_payout_minor=60000, net_minor=20000)
        run['estimate']['allowance_minor'] = 40000
        view['equity'][0]['balance_minor'] = 120000
        view['balance_minor'] = 120000
        report['difference']['balance_minor'] = 20000
        self.assertTrue(audit(report)['passed'])

    def test_an_unfunded_or_missing_run_cannot_block_an_overlap(self):
        report = self.report()
        skipped = copy.deepcopy(report['with_estimated_history']['runs'][0])
        skipped.update(id='skipped', status='skipped_overlap', blocked_by='missing')
        report['with_estimated_history']['runs'].append(skipped)
        self.assertIn('overlap_blocker_does_not_cover_entry', self.codes(report))

    def test_capital_mode_and_profit_stop_settings_are_checked(self):
        report = self.report()
        report['with_estimated_history']['capital_mode'] = 'fixed'
        self.assertIn('capital_mode_settings', self.codes(report))
        report = self.report()
        second = copy.deepcopy(report['with_estimated_history']['runs'][0])
        second.update(id='second', entry_at='2026-10-17T23:05:00Z', released_at='2026-10-17T23:08:21Z',
                      capital_at_entry_minor=110000, sizing_capital_at_entry_minor=110000)
        report['with_estimated_history']['runs'].append(second)
        report['settings']['profit_stop'] = 100
        for name in ('recorded_only', 'with_estimated_history'):
            report[name]['profit_stop_minor'] = 10000
        self.assertIn('entry_after_profit_stop', self.codes(report))

    def test_tables_use_exact_ngn_and_never_overwrite_existing_audits(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'comparison.json'
            source.write_text(json.dumps(self.report()))
            before = source.read_bytes()
            result = write_audit(source, root / 'audit')
            self.assertTrue(result['passed'])
            self.assertIn('100.00', (root / 'audit/daily.csv').read_text())
            self.assertEqual(before, source.read_bytes())
            with self.assertRaises(FileExistsError):
                write_audit(source, root / 'audit')


if __name__ == '__main__':
    unittest.main()
