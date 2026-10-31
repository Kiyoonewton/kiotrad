"""Compare added replay runs with original retained odds/result bytes."""
from datetime import datetime

from legacy_source_evidence import champion, first_win


def audit_sources(report, package):
    if report.get('version') != 'estimated-history-comparison-1.0.0' or report.get('research_only') is not True:
        raise ValueError('Use the saved estimated-history comparison report.')
    rows, qualifications, quotes, issues = [], [], [], []
    runs = report['with_estimated_history']['runs']
    by_id = {r['id']: r for r in runs}
    if len(by_id) != len(runs):
        raise ValueError('Duplicate run IDs in comparison.')
    selected = report['difference']['accepted_only_with_estimates']
    completed = {r['run_id'] for r in report['with_estimated_history']['equity']}
    baseline = {r['run_id'] for r in report['recorded_only']['equity']}
    if len(set(selected)) != len(selected) or set(selected) != completed - baseline:
        raise ValueError('Added-run IDs disagree with completed ledger differences.')
    diagnostics = {(r['competition'], r['season_id']): r for r in report['season_diagnostics']}

    def check(ok, run, code, expected=None, actual=None):
        if not ok:
            issues.append({'run_id': run, 'code': code, 'expected': expected, 'actual': actual})

    for identity in selected:
        if identity not in by_id:
            raise ValueError('Added run is absent from the saved comparison.')
        run, before = by_id[identity], len(issues)
        if not run.get('estimate') or run.get('funded', True) is False or run.get('net_minor') is None or run['status'].startswith('skipped_'):
            raise ValueError('Added-run list contains an uncompleted opportunity.')
        league = run['competition']
        bases = run['champion_basis']
        strategy = run['strategy_id']
        if strategy not in ('champion-next-win', 'champion-next-season') or len(bases) != (1 if strategy == 'champion-next-win' else 2):
            raise ValueError('Missing or invalid champion dependency chain.')
        chain = [s['season_id'] for s in run['timing_dependencies']]
        expected_chain = ([bases[0]['season_id'], run['season_id']] if strategy == 'champion-next-win'
                          else [bases[1]['season_id'], bases[0]['season_id'], run['season_id']])
        check(chain == expected_chain, identity, 'champion_dependency_chain_mismatch', expected_chain, chain)
        for basis in bases:
            source = package.season(league, basis['season_id'])
            table = champion(source)
            expected = basis['champion']
            leader = table['leader']
            check(table['complete'], identity, 'champion_season_incomplete', True, table['complete'])
            check(leader is not None and leader['team_uid'] == expected['team_uid'], identity,
                  'raw_champion_mismatch', expected['team_uid'], leader['team_uid'] if leader else None)
            qualifications.append({'run_id': identity, 'league': league, 'champion_season': basis['season_id'],
                                   'complete_240_results': table['complete'], 'expected_champion': expected['team_name'],
                                   'raw_unique_points_leader': leader['team'] if leader else None, 'top_three': table['top_three']})
        backed = bases[0]['champion']
        check(run['team'] == backed['team_name'], identity, 'backed_team_mismatch', backed['team_name'], run['team'])
        trigger_season = run['season_id'] if strategy == 'champion-next-win' else bases[0]['season_id']
        trigger_team = bases[0]['champion'] if strategy == 'champion-next-win' else bases[1]['champion']
        day, pattern = first_win(package.season(league, trigger_season), trigger_team['team_uid'])
        check(day == run['trigger_day'], identity, 'first_win_day_mismatch', run['trigger_day'], day)
        check(day is not None and day >= report['settings']['qualification'], identity, 'qualification_threshold')
        current = package.season(league, run['season_id'])
        first = (day + 1 if strategy == 'champion-next-win' else 1) if day else None
        for index, attempt in enumerate(run['attempts']):
            fixture = current.get(attempt['provider_match_id'])
            if fixture is None:
                check(False, identity, 'attempt_fixture_absent', attempt['provider_match_id'])
                continue
            code = attempt['outcome']
            uid = fixture['home_uid'] if code == 'H' else fixture['away_uid'] if code == 'A' else None
            check(uid == backed['team_uid'], identity, 'attempt_backs_wrong_team', backed['team_uid'], uid)
            check(first is not None and fixture['day'] == first + index == attempt['matchday'], identity, 'attempt_matchday_mismatch')
            digest = (attempt.get('price_provenance') or {}).get('source_sha256')
            evidence = fixture['sources'].get(digest, {})
            raw_price = evidence.get('prices', {}).get(code)
            check(raw_price == attempt['actual_odds_scaled'], identity, 'actual_quote_not_supported_by_cited_raw_hash', attempt['actual_odds_scaled'], raw_price)
            check(evidence.get('result') in ('H', 'D', 'A') and (evidence.get('result') == code) == attempt['won'], identity, 'raw_attempt_result_mismatch')
            quotes.append({'run_id': identity, 'role': 'actual_attempt', 'matchday': fixture['day'], 'fixture_id': fixture['id'],
                           'source_season': run['season_id'], 'outcome': code, 'expected_odds_scaled': attempt['actual_odds_scaled'],
                           'supported_odds_scaled': raw_price, 'source_sha256s': [digest] if digest in fixture['sources'] else [],
                           'quote_capture_id': attempt.get('price_capture_id'), 'raw_result': evidence.get('result')})
        for fixture in run['fixtures']:
            minimum = fixture.get('historical_minimum')
            if minimum is None:
                check(False, identity, 'sizing_minimum_missing', fixture.get('provider_match_id'))
                continue
            baseline = package.season(league, minimum['season_id']).get(minimum['provider_match_id'])
            actual = current.get(fixture['provider_match_id'])
            code = fixture['outcome']
            support = [h for h, s in baseline['sources'].items() if s['prices'].get(code) == minimum['odds_scaled']] if baseline else []
            check(bool(support), identity, 'sizing_quote_not_supported_by_raw', minimum['odds_scaled'])
            check(baseline is not None and actual is not None and (baseline['home_uid'], baseline['away_uid']) == (actual['home_uid'], actual['away_uid']), identity, 'sizing_venue_mismatch')
            previous = diagnostics.get((league, minimum['season_id']))
            check(previous is not None and previous['start_at'] == minimum['season_start_at']
                  and datetime.fromisoformat(previous['start_at']) < datetime.fromisoformat(run['season_start_at']), identity, 'sizing_source_not_earlier_season')
            quotes.append({'run_id': identity, 'role': 'sizing_minimum', 'matchday': fixture['matchday'],
                           'fixture_id': minimum['provider_match_id'], 'source_season': minimum['season_id'], 'outcome': code,
                           'expected_odds_scaled': minimum['odds_scaled'], 'supported_odds_scaled': minimum['odds_scaled'] if support else None,
                           'source_sha256s': support, 'quote_capture_id': minimum.get('capture_id'), 'raw_result': baseline['result'] if baseline else None})
        rows.append({'run_id': identity, 'league': league, 'season_id': run['season_id'], 'strategy': strategy,
                     'team': run['team'], 'trigger_season': trigger_season, 'trigger_team': trigger_team['team_name'],
                     'first_win_day': day, 'first_results': ''.join(r['result'] for r in pattern),
                     'first_win_fixtures': pattern, 'passed': len(issues) == before})
    return {'version': 'added-run-source-audit-1.0.0', 'passed': not issues, 'runs': rows,
            'champions': qualifications, 'quotes': quotes, 'issues': issues,
            'manifest_sha256': package.manifest_hash, 'verified_source_sha256s': sorted(package.read_hashes),
            'limits': ['Original retained archive bytes corroborate results, calculated champions, qualification and cited prices only.',
                       'Dates, adjacency and market availability remain modeled/unverified; this is not proof of live profitability.',
                       'Sizing checks corroborate the cited earlier quote, not the global minimum over every historical quote.',
                       'No MongoDB capture ID-to-file association is independently certified by this offline raw-file check.',
                       'Only newly completed run IDs are audited; later compounding changes and other benchmark runs are outside this report.']}
