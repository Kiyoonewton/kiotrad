import copy
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from audit_run_sources import write_audit
from legacy_source_evidence import SourcePackage
from run_source_audit import audit_sources


def stage(root, next_season=False):
    (root / 'files').mkdir()
    manifest, seasons, hashes = [], {}, {}
    for season, late in [('100', False), ('200', True)] + ([('300', False)] if next_season else []):
        days = {d: [] for d in range(1, 31)}
        rotation = list(range(1, 17))
        for round_ in range(1, 16):
            for i in range(8):
                for offset in (0, 15):
                    day = round_ + offset
                    home, away = (rotation[i], rotation[15-i]) if offset == 0 else (rotation[15-i], rotation[i])
                    code = 'H' if home == 1 else 'A'
                    result = ('A' if code == 'H' else 'H') if late and day < 5 else code
                    if 1 not in (home, away):
                        result = 'D'
                    event = {'id': f'vf:match:{season}{day:02}{i}', 'tournament_id': 'vf:tournament:14149',
                             'teams': {side: {'id': f'sr:competitor:{uid}', 'name': f'Team {uid}'} for side, uid in [('home', home), ('away', away)]},
                             'market': [{'id': 1, 'outcome': [
                                 {'id': str(n), 'odds': '3.00' if c == 'D' else '2.00', 'result': int(c == result)}
                                 for n, c in [(1, 'H'), (2, 'D'), (3, 'A')]]}]}
                    days[day].append(event)
            rotation = [rotation[0], rotation[-1], *rotation[1:-1]]
        seasons[season] = days
        for day, events in days.items():
            body = json.dumps({'queryUrl': f'vfc_stats_round_odds2/vf:season:{season}/{day}', 'doc': [{'data': {'odds': events}}]}).encode()
            digest = hashlib.sha256(body).hexdigest()
            (root / 'files' / (digest + '.json')).write_bytes(body)
            hashes[season, day] = digest
            manifest.append({'file': digest + '.json', 'sha256': digest, 'competition': 'virtual-euro', 'season_id': season, 'matchday': day})
    (root / 'plan.json').write_text('{}')
    (root / 'manifest.json').write_text(json.dumps({'package_version': 'legacy-history-package-1.0.0', 'files': manifest,
                                                  'plan_sha256': hashlib.sha256(b'{}').hexdigest()}))
    current, prior = ('300', '200') if next_season else ('200', '100')
    bases = [{'season_id': prior, 'champion': {'team_uid': '1', 'team_name': 'Team 1'}}]
    if next_season:
        bases.append({'season_id': '100', 'champion': {'team_uid': '1', 'team_name': 'Team 1'}})
    fixtures = []
    for day in range(1 if next_season else 6, 5 if next_season else 10):
        event = next(e for e in seasons[current][day] if any(t['id'] == 'sr:competitor:1' for t in e['teams'].values()))
        previous = next(e for events in seasons['100'].values() for e in events if e['teams'] == event['teams'])
        code = 'H' if event['teams']['home']['id'] == 'sr:competitor:1' else 'A'
        fixtures.append({'provider_match_id': event['id'], 'matchday': day, 'outcome': code,
                         'historical_minimum': {'season_id': '100', 'provider_match_id': previous['id'], 'odds_scaled': 20000,
                                                'capture_id': 'old', 'season_start_at': '2026-10-14T10:00:00+00:00'}})
    attempt = {**fixtures[0], 'actual_odds_scaled': 20000, 'price_provenance': {'source_sha256': hashes[current, fixtures[0]['matchday']]},
               'price_capture_id': 'current', 'won': True}
    run = {'id': 'example', 'competition': 'virtual-euro', 'season_id': current, 'strategy_id': 'champion-next-season' if next_season else 'champion-next-win',
           'team': 'Team 1', 'trigger_day': 5, 'champion_basis': bases,
           'timing_dependencies': [{'season_id': s} for s in (['100', '200', '300'] if next_season else ['100', '200'])], 'fixtures': fixtures, 'attempts': [attempt],
           'status': 'win', 'estimate': {'status': 'estimated'}, 'net_minor': 10000, 'season_start_at': '2026-10-14T13:44:00+00:00'}
    return {'version': 'estimated-history-comparison-1.0.0', 'research_only': True, 'settings': {'qualification': 5},
            'recorded_only': {'equity': []}, 'with_estimated_history': {'runs': [run], 'equity': [{'run_id': 'example'}]}, 'difference': {'accepted_only_with_estimates': ['example']},
            'season_diagnostics': [{'competition': 'virtual-euro', 'season_id': '100', 'start_at': '2026-10-14T10:00:00+00:00'}]}


class RunSourceAuditTest(unittest.TestCase):
    def test_both_strategy_chains_are_independently_supported_by_original_bytes(self):
        for next_season in (False, True):
            with self.subTest(next_season=next_season), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                report = stage(root, next_season)
                result = audit_sources(report, SourcePackage(root))
                self.assertTrue(result['passed'], result['issues'])
                self.assertEqual(result['runs'][0]['first_results'], 'LLLLW')
                self.assertEqual(len(result['quotes']), 5)
                self.assertEqual(len(result['verified_source_sha256s']), 90 if next_season else 60)

    def test_changed_first_win_champion_and_actual_quote_hash_are_detected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root)
            run = report['with_estimated_history']['runs'][0]
            run['trigger_day'] = 6
            run['champion_basis'][0]['champion']['team_uid'] = '2'
            run['attempts'][0]['price_provenance']['source_sha256'] = '0' * 64
            result = audit_sources(report, SourcePackage(root))
            self.assertFalse(result['passed'])
            codes = {r['code'] for r in result['issues']}
            self.assertTrue({'raw_champion_mismatch', 'first_win_day_mismatch', 'actual_quote_not_supported_by_cited_raw_hash'} <= codes)

    def test_partial_champion_season_and_wrong_sizing_venue_do_not_pass(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root)
            manifest = json.loads((root / 'manifest.json').read_text())
            manifest['files'] = [r for r in manifest['files'] if not (r['season_id'] == '100' and r['matchday'] == 30)]
            (root / 'manifest.json').write_text(json.dumps(manifest))
            package = SourcePackage(root)
            source = package.season('virtual-euro', '100')
            run = report['with_estimated_history']['runs'][0]
            wrong = next(m for m in source.values() if m['home_uid'] != '1' and m['away_uid'] != '1')
            run['fixtures'][0]['historical_minimum']['provider_match_id'] = wrong['id']
            codes = {r['code'] for r in audit_sources(report, package)['issues']}
            self.assertIn('champion_season_incomplete', codes)
            self.assertIn('sizing_venue_mismatch', codes)

    def test_tampered_source_bytes_stop_before_creating_a_report(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root)
            manifest = json.loads((root / 'manifest.json').read_text())
            (root / 'files' / manifest['files'][0]['file']).write_text('{}')
            with self.assertRaisesRegex(ValueError, 'checksum'):
                audit_sources(report, SourcePackage(root))

    def test_tied_points_never_certify_a_champion(self):
        from legacy_source_evidence import champion
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            stage(root)
            matches = SourcePackage(root).season('virtual-euro', '100')
            for row in matches.values():
                row['result'] = 'D'
            result = champion(matches)
            self.assertTrue(result['complete'])
            self.assertIsNone(result['leader'])

    def test_swapped_dependency_chain_and_forged_sizing_price_fail(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root, True)
            run = report['with_estimated_history']['runs'][0]
            run['timing_dependencies'].reverse()
            run['fixtures'][0]['historical_minimum']['odds_scaled'] = 19999
            codes = {r['code'] for r in audit_sources(report, SourcePackage(root))['issues']}
            self.assertIn('champion_dependency_chain_mismatch', codes)
            self.assertIn('sizing_quote_not_supported_by_raw', codes)

    def test_output_is_read_only_pinned_and_does_not_overwrite(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root)
            source = root / 'comparison.json'
            source.write_text(json.dumps(report))
            before = source.read_bytes()
            result = write_audit(source, root, root / 'audit')
            self.assertTrue(result['passed'])
            self.assertEqual(result['comparison_sha256'], hashlib.sha256(before).hexdigest())
            self.assertTrue((root / 'audit/champions.csv').exists())
            self.assertEqual(source.read_bytes(), before)
            with self.assertRaises(FileExistsError):
                write_audit(source, root, root / 'audit')

    def test_missing_and_uncompleted_added_runs_are_not_silently_ignored(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            report = stage(root)
            bad = copy.deepcopy(report)
            bad['difference']['accepted_only_with_estimates'] = ['absent']
            with self.assertRaises(ValueError):
                audit_sources(bad, SourcePackage(root))
            report['with_estimated_history']['runs'][0]['net_minor'] = None
            with self.assertRaises(ValueError):
                audit_sources(report, SourcePackage(root))


if __name__ == '__main__':
    unittest.main()
