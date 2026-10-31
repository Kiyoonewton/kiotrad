import copy
import tempfile
import unittest
from pathlib import Path

from audit_scenario_sources import audit
from legacy_source_evidence import SourcePackage
from test_run_source_audit import stage


def example(root, next_season=False):
    original = stage(root, next_season)
    run = original['with_estimated_history']['runs'][0]
    for key in ('champion_basis', 'timing_dependencies'):
        del run[key]
    run['id'] = f"virtual-euro:{run['season_id']}:{run['strategy_id']}"
    run['released_at'] = '2026-10-18T11:01:35Z'
    export = dict(version='scenario-dashboard-export-1.0.0', requested_settings=dict(qualification=5, attempts=4))
    export['view'] = dict(settings=copy.deepcopy(export['requested_settings']), runs=[run])
    dependencies = [dict(run_id=run['id'], previous_season='200' if next_season else '100', older_season='100' if next_season else '')]
    return export, dependencies


class ScenarioSourcesTest(unittest.TestCase):
    def test_both_strategies_reconstruct_without_exported_champion_metadata(self):
        for next_season in (False, True):
            with self.subTest(next_season=next_season), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                export, chain = example(root, next_season)
                result = audit(export, chain, SourcePackage(root))
                self.assertTrue(result['passed'], result['issues'])
                self.assertEqual(result['runs'][0]['pattern'], 'LLLLW')

    def test_wrong_champion_trigger_quote_and_result_fail(self):
        for field, value in [('team', 'Wrong'), ('trigger_day', 6), ('actual_odds_scaled', 25000), ('won', False)]:
            with self.subTest(field=field), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                export, chain = example(root)
                run = export['view']['runs'][0]
                (run if field in ('team', 'trigger_day') else run['attempts'][0])[field] = value
                self.assertFalse(audit(export, chain, SourcePackage(root))['passed'])

    def test_cited_hash_is_not_substituted_and_duplicates_are_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            export, chain = example(root)
            export['view']['runs'][0]['attempts'][0]['price_provenance']['source_sha256'] = '0' * 64
            self.assertFalse(audit(export, chain, SourcePackage(root))['passed'])
            with self.assertRaises(ValueError):
                audit(export, chain + chain, SourcePackage(root))

    def test_incomplete_champion_and_tampered_bytes_do_not_pass(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            export, chain = example(root)
            package = SourcePackage(root)
            package.season('virtual-euro', '100').pop(next(iter(package.cache['virtual-euro', '100'])))
            self.assertFalse(audit(export, chain, package)['passed'])
            path = next((root / 'files').glob('*.json'))
            path.write_text('{}')
            with self.assertRaises(ValueError):
                audit(export, chain, SourcePackage(root))
