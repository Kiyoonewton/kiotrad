"""Independent integer ledger checks for estimated-history replay output."""
from collections import defaultdict
from datetime import datetime
from zoneinfo import ZoneInfo

LAGOS = ZoneInfo('Africa/Lagos')
SCENARIOS = ('recorded_only', 'with_estimated_history')


def instant(value):
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise ValueError('Timestamp must have an explicit timezone.')
    return parsed


def minor(value):
    if type(value) is not int:
        raise ValueError('Financial amounts must be integer minor units, not floats or booleans.')
    return value


def funded(run):
    return bool(run.get('estimate')) and run.get('funded', True) is not False and not run['status'].startswith('skipped_')


def booked(run):
    return funded(run) and run.get('released_at') is not None and run.get('net_minor') is not None


class LedgerAudit:
    def __init__(self, scenario):
        self.scenario = scenario
        self.issues = []

    def check(self, condition, code, run=None, expected=None, actual=None):
        if not condition:
            self.issues.append({'scenario': self.scenario, 'run_id': run, 'code': code,
                                'expected': expected, 'actual': actual})

    def attempts(self, run):
        key = run['id']
        spent = payout = 0
        profit = minor(run['estimate']['plan']['profit_per_attempt_minor'])
        self.check(profit >= 100 and profit % 100 == 0, 'invalid_frozen_profit', key)
        for index, attempt in enumerate(run['attempts'], 1):
            stake, odds = minor(attempt['stake_minor']), attempt.get('actual_odds_scaled')
            self.check(attempt['attempt'] == index, 'attempt_sequence', key, index, attempt['attempt'])
            self.check(stake >= 10000 and stake % 100 == 0, 'stake_minimum_or_whole_naira', key, '>=10000 and whole naira', stake)
            target = spent + index * profit
            if type(odds) is int and 10000 < odds <= 10000000:
                divisor = (odds - 10000) * 100
                expected = max(10000, ((target * 10000 + divisor - 1) // divisor) * 100)
                self.check(stake == expected, 'stake_recovery_calculation', key, expected, stake)
            else:
                self.check(False, 'invalid_actual_odds', key, 'scaled integer odds >10000', odds)
            self.check(attempt['target_profit_minor'] == index * profit, 'frozen_profit_target', key,
                       index * profit, attempt['target_profit_minor'])
            paid = attempt['payout_minor']
            if paid is None:
                self.check(not booked(run), 'settled_attempt_has_no_payout', key)
            else:
                paid = minor(paid)
                won = attempt.get('won')
                self.check(type(won) is bool, 'paid_attempt_missing_result', key)
                if type(odds) is int and type(won) is bool:
                    expected = stake * odds // 10000 if won else 0
                    self.check(paid == expected, 'attempt_payout', key, expected, paid)
                self.check(not won or index == len(run['attempts']), 'attempt_after_win', key)
                payout += paid
            spent += stake
        self.check(spent == minor(run['total_staked_minor']), 'run_stake_sum', key, spent, run['total_staked_minor'])
        self.check(payout == minor(run['gross_payout_minor']), 'run_payout_sum', key, payout, run['gross_payout_minor'])
        self.check(spent <= minor(run['estimate']['allowance_minor']), 'allowance_exceeded', key)
        if booked(run):
            if run['status'] == 'win':
                self.check(bool(run['attempts']) and run['attempts'][-1].get('won') is True, 'win_status_without_winning_attempt', key)
            if run['status'] in ('four_losses', 'attempt_limit_losses'):
                self.check(len(run['attempts']) == len(run['fixtures']), 'loss_window_attempt_count', key)
            if run['status'] in ('four_losses', 'attempt_limit_losses', 'season_end'):
                self.check(all(a.get('won') is False for a in run['attempts']), 'loss_status_with_nonloss_attempt', key)
            self.check(minor(run['net_minor']) == payout - spent, 'run_net', key, payout - spent, run['net_minor'])

    def inspect(self, view, settings):
        if view.get('status') != 'available' or view.get('settings') != settings:
            raise ValueError('Missing completed scenario or differing settings; no partial audit accepted.')
        initial = minor(view['starting_capital_minor'])
        self.check(initial == minor(settings['capital']) * 100, 'starting_capital_settings')
        self.check(view['max_attempts'] == settings['attempts'], 'attempt_limit_settings')
        self.check(view['allowance_multiplier'] == settings['allowance'], 'allowance_settings')
        self.check(view['capital_mode'] == settings['capital_mode'], 'capital_mode_settings')
        self.check(view['profit_stop_minor'] == settings['profit_stop'] * 100, 'profit_stop_settings')
        by_id, rows = {}, []
        for run in view['runs']:
            self.check(run['id'] not in by_id, 'duplicate_run_id', run['id'])
            by_id[run['id']] = run
            if funded(run):
                self.attempts(run)
                self.check(len(run['attempts']) <= settings['attempts'], 'too_many_attempts', run['id'])
                self.check((run.get('released_at') is None) == (run.get('net_minor') is None), 'partial_settlement_fields', run['id'])
            rows.append({'scenario': self.scenario, 'run_id': run['id'], 'league': run['competition'],
                         'season_id': run['season_id'], 'strategy': run['strategy_id'], 'team': run.get('team'),
                         'status': run['status'], 'booked': booked(run),
                         'estimated_dependency': bool(run.get('estimated_timing_dependency')),
                         'entry_wat': instant(run['entry_at']).astimezone(LAGOS).isoformat(),
                         'release_wat': instant(run['released_at']).astimezone(LAGOS).isoformat() if run.get('released_at') else None,
                         'stake_minor': run.get('total_staked_minor'), 'payout_minor': run.get('gross_payout_minor'),
                         'net_minor': run.get('net_minor'), 'blocked_by': run.get('blocked_by'),
                         'timing_dependencies': run.get('timing_dependencies', []),
                         'champion_basis': run.get('champion_basis'), 'overlap_basis': run.get('overlap_basis')})
        for run in view['runs']:
            if run['status'] in ('skipped_overlap', 'skipped_simultaneous_entry'):
                blocker = by_id.get(run.get('blocked_by'))
                valid = blocker is not None and funded(blocker) and instant(blocker['entry_at']) <= instant(run['entry_at'])
                valid = valid and (blocker.get('released_at') is None or instant(run['entry_at']) <= instant(blocker['released_at']))
                self.check(valid, 'overlap_blocker_does_not_cover_entry', run['id'])
                if run['status'] == 'skipped_simultaneous_entry' and blocker is not None:
                    self.check(instant(blocker['entry_at']) == instant(run['entry_at']), 'simultaneous_entries_differ', run['id'])
        balance, previous, pending = initial, None, None
        for run in sorted((r for r in view['runs'] if funded(r)), key=lambda r: (instant(r['entry_at']), r['id'])):
            self.check(settings['profit_stop'] == 0 or balance - initial < settings['profit_stop'] * 100, 'entry_after_profit_stop', run['id'])
            self.check(pending is None, 'funded_entry_while_previous_unresolved', run['id'], None, pending)
            if previous is not None:
                self.check(instant(run['entry_at']) > instant(previous['released_at']), 'accepted_windows_overlap', run['id'],
                           'entry strictly after ' + previous['released_at'], run['entry_at'])
            self.check(minor(run['capital_at_entry_minor']) == balance, 'capital_at_entry', run['id'], balance, run['capital_at_entry_minor'])
            sizing = initial if settings['capital_mode'] == 'fixed' else balance
            self.check(run['sizing_capital_at_entry_minor'] == sizing, 'sizing_capital', run['id'], sizing, run['sizing_capital_at_entry_minor'])
            self.check(minor(run['estimate']['allowance_minor']) <= balance, 'allowance_exceeds_balance', run['id'])
            if booked(run):
                self.check(instant(run['entry_at']) <= instant(run['released_at']) <= instant(view['as_of']), 'invalid_release_window', run['id'])
                balance += minor(run['net_minor'])
                self.check(balance >= 0, 'negative_settled_balance', run['id'])
                previous = run
            else:
                pending = run['id']
        self.check(view['active_run_id'] == pending, 'active_run_identity', expected=pending, actual=view['active_run_id'])
        self.check(balance == minor(view['balance_minor']), 'ending_balance', expected=balance, actual=view['balance_minor'])
        completed = sorted((r for r in view['runs'] if booked(r)), key=lambda r: (instant(r['released_at']), r['id']))
        self.check(len(completed) == len(view['equity']), 'equity_count', expected=len(completed), actual=len(view['equity']))
        running = initial
        for run, point in zip(completed, view['equity']):
            running += minor(run['net_minor'])
            self.check(point['run_id'] == run['id'] and instant(point['at']) == instant(run['released_at']), 'equity_run_order', run['id'])
            self.check(minor(point['balance_minor']) == running, 'equity_balance', run['id'], running, point['balance_minor'])
        days = defaultdict(lambda: {'settled_runs': 0, 'estimated_dependency_runs': 0, 'stake_minor': 0, 'payout_minor': 0, 'net_minor': 0})
        for run in completed:
            date = instant(run['released_at']).astimezone(LAGOS).date().isoformat()
            day = days[date]
            day['settled_runs'] += 1
            day['estimated_dependency_runs'] += bool(run.get('estimated_timing_dependency'))
            for target, source in [('stake_minor', 'total_staked_minor'), ('payout_minor', 'gross_payout_minor'), ('net_minor', 'net_minor')]:
                day[target] += minor(run[source])
        daily = [{'scenario': self.scenario, 'date_wat': date, **value} for date, value in sorted(days.items())]
        self.check(sum(d['net_minor'] for d in daily) == balance - initial, 'daily_net_reconciliation')
        return {'scenario': self.scenario, 'passed': not self.issues, 'completed_runs': len(completed),
                'starting_capital_minor': initial, 'ending_balance_minor': balance,
                'total_staked_minor': sum(d['stake_minor'] for d in daily),
                'gross_payout_minor': sum(d['payout_minor'] for d in daily),
                'net_minor': balance - initial, 'pending_run_id': pending,
                'pending_staked_minor': by_id[pending]['total_staked_minor'] if pending else 0}, rows, daily


def audit(report):
    if report.get('version') != 'estimated-history-comparison-1.0.0' or report.get('research_only') is not True or report.get('benchmark_eligible') is not False:
        raise ValueError('Use a complete research-only estimated-history comparison report.')
    summaries, runs, days, issues, attempts = [], [], [], [], []
    for scenario in SCENARIOS:
        check = LedgerAudit(scenario)
        summary, rows, daily = check.inspect(report[scenario], report['settings'])
        summaries.append(summary)
        runs.extend(rows)
        days.extend(daily)
        issues.extend(check.issues)
        for run in report[scenario]['runs']:
            if not funded(run):
                continue
            for attempt in run['attempts']:
                attempts.append({'scenario': scenario, 'run_id': run['id'], 'attempt': attempt['attempt'],
                    'matchday': attempt.get('matchday'), 'fixture_id': attempt.get('provider_match_id'),
                    'scheduled_start_at': attempt.get('scheduled_start_at'), 'result_observed_at': attempt.get('result_confirmed_at'),
                    'odds_scaled': attempt.get('actual_odds_scaled'), 'stake_minor': attempt['stake_minor'],
                    'payout_minor': attempt['payout_minor'], 'won': attempt.get('won'),
                    'quote_capture_id': attempt.get('price_capture_id'), 'quote_provenance': attempt.get('price_provenance'),
                    'price_timing_verified': attempt.get('price_timing_verified', False),
                    'historical_minimum': attempt.get('historical_minimum'),
                    'estimated_dependency': bool(run.get('estimated_timing_dependency'))})
    baseline, hypothetical = (report[s] for s in SCENARIOS)
    difference = LedgerAudit('comparison')
    expected = hypothetical['balance_minor'] - baseline['balance_minor']
    difference.check(report['difference']['balance_minor'] == expected, 'reported_balance_difference', expected=expected, actual=report['difference']['balance_minor'])
    ids = lambda view: {r['id'] for r in view['runs'] if booked(r)}
    for field, expected in [('accepted_only_with_estimates', ids(hypothetical) - ids(baseline)), ('accepted_only_recorded', ids(baseline) - ids(hypothetical))]:
        actual = report['difference'][field]
        difference.check(len(actual) == len(set(actual)) and set(actual) == expected, 'reported_' + field, expected=sorted(expected), actual=actual)
    issues.extend(difference.issues)
    return {'version': 'comparison-ledger-audit-1.0.0', 'passed': not issues,
            'summaries': summaries, 'issues': issues, 'runs': runs, 'daily': days, 'attempts': attempts,
            'limits': ['Arithmetic and ledger consistency only; source odds, results, champions and kickoff dates are not independently certified.',
                       'Estimated kickoff uncertainty remains unknown. A passing audit does not establish profitability.',
                       'Daily totals book an entire accepted settled run on its Lagos release date, including stakes placed before midnight.',
                       'Stake and payout totals exclude unresolved and skipped runs; pending exposure is separate.',
                       'Later recorded runs may change through compounding, even without a direct estimated timing dependency.']}
