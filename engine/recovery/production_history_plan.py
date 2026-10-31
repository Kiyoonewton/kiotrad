"""Review append-only evidence candidates; never authorize or perform an import."""
from collections import Counter

from legacy_source_evidence import champion


def assess_season(league, season, incoming, inventory):
    rows, conflicts = [], False
    for identity, fixture in sorted(incoming.items()):
        stored = inventory.get(identity, {'fixtures': [], 'prices': [], 'invalid_quotes': 0})
        issues = set()
        for old in stored['fixtures']:
            expected = {'competition': league, 'season_id': season, 'matchday': fixture['day'],
                        'home_uid': fixture['home_uid'], 'away_uid': fixture['away_uid']}
            issues.update(k for k, value in expected.items() if old.get(k) != value)
            if old.get('result') is not None and fixture['result'] is not None and old['result'] != fixture['result']:
                issues.add('result')
        if any(p['competition'] != league for p in stored['prices']):
            issues.add('quote_competition')
        times = {f['scheduled_start_at'] for f in stored['fixtures'] if f.get('scheduled_start_at')}
        if len(times) > 1:
            issues.add('production_timing_conflict')
        variants = {tuple(s['prices'][c] for c in ('H', 'D', 'A')) for s in fixture['sources'].values()
                    if all(c in s['prices'] for c in ('H', 'D', 'A'))}
        existing = {tuple(p['prices'][c] for c in ('H', 'D', 'A')) for p in stored['prices']}
        fixture_action = 'append_fixture_candidate' if not stored['fixtures'] else 'preserve_existing_fixture'
        if len(variants) > 1:
            price_action = 'ambiguous_source_price_preserve_raw_only'
        elif not variants:
            price_action = 'source_price_missing'
        elif variants & existing:
            price_action = 'matching_price_skip'
        elif existing:
            price_action = 'different_production_price_preserve'
        else:
            price_action = 'append_price_candidate'
        if issues:
            conflicts = True
        rows.append({'league': league, 'season_id': season, 'fixture_id': identity, 'matchday': fixture['day'],
                     'fixture': fixture['home_name'] + ' vs ' + fixture['away_name'],
                     'source_result': fixture['result'], 'production_results': sorted({f['result'] for f in stored['fixtures'] if f.get('result') is not None}),
                     'production_schedule_utc': sorted(times), 'source_price_variants_scaled': sorted(variants),
                     'production_price_variants_scaled': sorted(existing),
                     'fixture_action': fixture_action, 'price_action': price_action,
                     'timing_action': 'preserve_recorded_schedule' if times else 'remain_untimed',
                     'source_result_available': fixture['result'] is not None,
                     'existing_unconfirmed_result': bool(stored['fixtures']) and fixture['result'] is not None
                         and not any(f.get('result') is not None for f in stored['fixtures']),
                     'invalid_production_quotes': stored['invalid_quotes'], 'conflicts': sorted(issues)})
    for row in rows:
        row['season_blocked'] = conflicts
        if conflicts:
            row['fixture_action'] = row['price_action'] = 'blocked_season_review_required'
    integrity = champion(incoming)
    adds = any(r['fixture_action'] == 'append_fixture_candidate' or r['price_action'] == 'append_price_candidate' for r in rows)
    summary = {'league': league, 'season_id': season, 'blocked': conflicts,
               'disposition': 'blocked_conflict' if conflicts else 'append_evidence_candidate' if adds else 'raw_preservation_only_candidate',
               'source_fixtures': len(incoming), 'full_result_integrity': integrity['complete'],
               'fixture_actions': dict(Counter(r['fixture_action'] for r in rows)),
               'price_actions': dict(Counter(r['price_action'] for r in rows)),
               'timing_actions': dict(Counter(r['timing_action'] for r in rows)),
               'financial_eligibility_added': False}
    return summary, rows


def strategy_alignment(snapshot, code_hashes, replay_version, capital_minor):
    if snapshot.get('schema_version') != 1 or type(snapshot.get('capital_minor')) is not int or snapshot['capital_minor'] < 10000:
        raise ValueError('Invalid production strategy snapshot.')
    remote = snapshot.get('code_hashes', {})
    differences = sorted(k for k in set(remote) | set(code_hashes) if remote.get(k) != code_hashes.get(k))
    return {'code_matches': not differences, 'differing_files': differences,
            'replay_version_matches': snapshot.get('replay_version') == replay_version,
            'production_replay_version': snapshot.get('replay_version'), 'local_replay_version': replay_version,
            'production_capital_minor': snapshot['capital_minor'], 'comparison_capital_minor': capital_minor,
            'benchmark_capital_matches': snapshot['capital_minor'] == capital_minor}


def final_plan(summaries, rows, alignment, provenance):
    blocked = [s['league'] + ':' + s['season_id'] for s in summaries if s['blocked']]
    return {'version': 'production-history-plan-1.0.0', 'mode': 'read_only_production_plan', 'applied': False,
            'production_write_authorized': False, 'strategy_alignment': alignment,
            'blocked_seasons': blocked, 'seasons': summaries, 'fixtures': rows, 'provenance': provenance,
            'fixture_action_counts': dict(Counter(r['fixture_action'] for r in rows)),
            'price_action_counts': dict(Counter(r['price_action'] for r in rows)),
            'timing_action_counts': dict(Counter(r['timing_action'] for r in rows)),
            'required_before_apply': [
                'Review this complete plan, identity/result conflicts and ambiguous prices.',
                'Align production/local strategy code; separately review benchmark capital/settings differences.',
                'Back up production MongoDB, permanent raw evidence, strategy settings and current usable derived views; verify restore into an isolated database.',
                'Prepare a separate idempotent raw-first production import with an append-only ledger and no replacement of verified fixtures/prices.',
                'Re-audit against current production immediately before any explicitly authorized write.',
                'Verify overview and reusable Simulator readiness, daily totals and retained last-good views after any future deployment.',
            ], 'timing_policy': {'apply_estimated_dates': False, 'new_fixture_timing': 'unknown',
                                'estimated_replay': 'separate_research_only'},
            'rollback_policy': 'No rollback needed for this audit. Future import rollback must use a tested backup or isolated staging; never drop production collections or volumes.',
            'limits': ['Append actions are review candidates, not an executable or approved import.',
                       'Capture reads use a fixed observation cutoff but are not a transactional MongoDB snapshot; late backfills can change results.',
                       'Existing unconfirmed results are identified but not automatically replaced from archived outcomes.',
                       'No source odds/results/champions, timestamp estimates, settings, balances or dashboard data are written by this audit.']}
