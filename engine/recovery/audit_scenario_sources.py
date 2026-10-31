"""Corroborate selected exported Simulator runs against retained raw bytes."""
import argparse
import csv
import hashlib
import json
import re
from pathlib import Path

from audit_history_comparison import table
from legacy_source_evidence import SourcePackage, champion, first_win


def audit(export, dependencies, package):
    if export.get('version') != 'scenario-dashboard-export-1.0.0':
        raise ValueError('Use the downloaded Simulator reconciliation export.')
    settings = export['requested_settings']
    if export['view']['settings'] != settings:
        raise ValueError('Requested and exported settings differ.')
    runs = export['view']['runs']
    by_id = {r['id']: r for r in runs}
    if len(by_id) != len(runs) or not dependencies:
        raise ValueError('Duplicate exported run IDs or empty dependency list.')
    identities = [d['run_id'] for d in dependencies]
    if len(set(identities)) != len(identities):
        raise ValueError('Duplicate selected run IDs.')
    issues, rows, leaders, quotes = [], [], [], []

    def check(ok, identity, code, expected=None, actual=None):
        if not ok:
            issues.append(dict(run_id=identity, code=code, expected=expected, actual=actual))

    for dependency in dependencies:
        identity = dependency['run_id']
        run = by_id[identity]
        league, season, strategy = run['competition'], str(run['season_id']), run['strategy_id']
        if identity != f'{league}:{season}:{strategy}' or strategy not in ('champion-next-win', 'champion-next-season'):
            raise ValueError('Invalid selected run identity or strategy.')
        if run.get('funded', True) is False or run.get('net_minor') is None or run['status'].startswith('skipped_') or not run.get('released_at') or not run.get('attempts'):
            raise ValueError('Selected run is not funded and settled.')
        previous, older = dependency['previous_season'], dependency.get('older_season', '')
        chain = [previous, season] if strategy == 'champion-next-win' else [older, previous, season]
        if any(not re.fullmatch(r'\d+', s) for s in chain) or any(int(a) >= int(b) for a, b in zip(chain, chain[1:])):
            raise ValueError('Invalid dependency season order.')
        before = len(issues)
        champions = {}
        for source_season in chain[:-1]:
            ranking = champion(package.season(league, source_season))
            leader = ranking['leader']
            check(ranking['complete'], identity, 'incomplete_champion_season', True, ranking['complete'])
            check(leader is not None, identity, 'unique_champion_unverified')
            champions[source_season] = leader
            leaders.append(dict(run_id=identity, season_id=source_season, complete=ranking['complete'], top_three=ranking['top_three']))
        backed = champions[previous]
        trigger = backed if strategy == 'champion-next-win' else champions[older]
        trigger_season = season if strategy == 'champion-next-win' else previous
        day, pattern = first_win(package.season(league, trigger_season), trigger['team_uid']) if trigger else (None, [])
        check(backed is not None and backed['team'] == run['team'], identity, 'backed_champion_mismatch', run['team'], backed['team'] if backed else None)
        check(day == run['trigger_day'], identity, 'first_win_day_mismatch', run['trigger_day'], day)
        check(day is not None and day >= settings['qualification'], identity, 'qualification_not_met')
        current = package.season(league, season)
        first = day + 1 if day is not None and strategy == 'champion-next-win' else 1
        for index, attempt in enumerate(run['attempts']):
            fixture = current.get(attempt['provider_match_id'])
            code = attempt['outcome']
            check(code in ('H', 'A'), identity, 'invalid_backed_outcome')
            uid = fixture.get('home_uid' if code == 'H' else 'away_uid') if fixture else None
            check(fixture is not None and backed is not None and uid == backed['team_uid'], identity, 'attempt_fixture_or_team_mismatch')
            check(fixture is not None and fixture['day'] == attempt['matchday'] == first + index, identity, 'attempt_day_mismatch')
            digest = (attempt.get('price_provenance') or {}).get('source_sha256')
            sources = fixture['sources'] if fixture else {}
            # A cited hash must support the quote; do not silently substitute another file.
            support = {h: s for h, s in sources.items() if (not digest or h == digest)
                       and s['prices'].get(code) == attempt['actual_odds_scaled']
                       and s['result'] in ('H', 'D', 'A') and (s['result'] == code) == attempt['won']}
            check(bool(support), identity, 'attempt_quote_or_result_unsupported', attempt['actual_odds_scaled'])
            quotes.append(dict(run_id=identity, matchday=attempt['matchday'], fixture_id=attempt['provider_match_id'],
                               odds_scaled=attempt['actual_odds_scaled'], won=attempt['won'], cited_sha256=digest,
                               supported_sha256s=sorted(support), fixture=(fixture['home_name'] + ' vs ' + fixture['away_name']) if fixture else None))
        check(len(run['attempts']) <= settings['attempts'], identity, 'too_many_attempts')
        check(all(not a['won'] for a in run['attempts'][:-1]), identity, 'continued_after_win')
        check((run['status'] == 'win') == bool(run['attempts'][-1]['won']), identity, 'terminal_result_mismatch')
        rows.append(dict(run_id=identity, team=run['team'], previous_season=previous, older_season=older,
                         trigger_team=trigger['team'] if trigger else None, trigger_season=trigger_season,
                         first_win_day=day, pattern=''.join(p['result'] for p in pattern), qualification_fixtures=pattern,
                         attempts=len(run['attempts']), passed=len(issues) == before))
    return dict(version='scenario-source-audit-1.0.0', passed=not issues, runs=rows, champions=leaders, quotes=quotes,
                issues=issues, settings=settings, manifest_sha256=package.manifest_hash,
                verified_source_sha256s=sorted(package.read_hashes),
                limits=['Dependency season IDs are explicit review inputs; this audit does not certify adjacency or kickoff timing.',
                        'Historical raw odds corroborate prices and results, not pre-match availability.',
                        'Only selected runs are checked. Sizing minima, arithmetic and global selection are separate audits.',
                        'No database writes or strategy changes. Settlement timing is unchanged.'])


def write(export_path, dependencies_path, package_path, destination):
    body, chain_body = Path(export_path).read_bytes(), Path(dependencies_path).read_bytes()
    with Path(dependencies_path).open() as stream:
        dependencies = list(csv.DictReader(stream))
    package = SourcePackage(package_path, lambda league, season, count: print(f'Raw evidence: {league}:{season} | {count} files', flush=True))
    result = audit(json.loads(body), dependencies, package)
    result.update(export_sha256=hashlib.sha256(body).hexdigest(), dependencies_sha256=hashlib.sha256(chain_body).hexdigest())
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(result, indent=2) + '\n')
    for name in ('runs', 'champions', 'quotes', 'issues'):
        table(destination / (name + '.csv'), result[name])
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('export')
    parser.add_argument('--dependencies', required=True)
    parser.add_argument('--package', required=True)
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    try:
        result = write(args.export, args.dependencies, args.package, args.destination)
    except (OSError, ValueError, KeyError, TypeError, IndexError, AttributeError) as error:
        parser.exit(1, 'Scenario source audit stopped: ' + str(error) + '\n')
    print('Scenario source audit:', 'PASS' if result['passed'] else 'FAIL')
    for row in result['runs']:
        print(row['run_id'], '|', row['team'], '|', row['pattern'], '|', 'PASS' if row['passed'] else 'FAIL')
    print('Issues:', len(result['issues']))
    print('Tables:', args.destination)
    print('Source corroboration only. Timing and market availability are not certified.')
    if not result['passed']:
        parser.exit(1)
