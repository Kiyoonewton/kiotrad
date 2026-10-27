import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from audit_discovery_evidence import audit
from discovery_order import assess_order, failure_evidence


class DiscoveryEvidenceTest(unittest.TestCase):
    def test_anchor_direction_comes_from_times_not_scraper_label(self):
        ids = ['30', '20', '10']
        identities = {s: {'english'} for s in ids}
        result = assess_order(ids, identities, {('english', '20'): 10000, ('english', '30'): 16720}, 'english')
        self.assertEqual(result['observed_direction'], 'newest_first')
        self.assertEqual(result['candidate_oldest_first'], ['10', '20', '30'])
        self.assertEqual(result['unanchored_prefix_seasons'], 1)
        self.assertEqual(result['status'], 'anchored_candidate')
        self.assertFalse(result['eligible_for_estimation'])

    def test_mixed_unknown_duplicate_and_wrong_labels_are_not_promoted(self):
        identities = {'10': {'english'}, '20': {'euro'}}
        self.assertIn('mixed_leagues', assess_order(['10', '20'], identities, {})['issues'])
        self.assertIn('unknown_or_conflicting_league', assess_order(['10', '30'], identities, {})['issues'])
        self.assertIn('duplicate_ids', assess_order(['10', '10'], identities, {})['issues'])
        self.assertIn('declared_league_mismatch', assess_order(['10'], identities, {}, 'euro')['issues'])

    def test_missing_season_or_break_is_a_cadence_mismatch_not_filled(self):
        ids = ['10', '20']
        result = assess_order(ids, {s: {'english'} for s in ids}, {('english', '10'): 10000, ('english', '20'): 23440})
        self.assertEqual(result['status'], 'cadence_mismatch')
        self.assertEqual(result['anchor_segments'][0]['error_seconds'], 6720)
        self.assertFalse(result['eligible_for_estimation'])

    def test_nonmonotonic_anchors_refuse_sequence(self):
        ids = ['10', '20', '30']
        result = assess_order(ids, {s: {'english'} for s in ids},
                              {('english', '10'): 10000, ('english', '20'): 9000, ('english', '30'): 11000})
        self.assertEqual(result['status'], 'anchor_order_conflict')

    def test_failures_are_collection_reports_not_market_blockages(self):
        result = failure_evidence({'error': 'Show more did not load additional rows',
                                   'processed': [{'season_id': '10', 'status': 'network'}]})
        self.assertEqual(result[0]['kind'], 'archive_ui_failure')
        self.assertEqual(result[1]['kind'], 'collector_reported_network')

    def test_preserves_exact_diagnostic_bytes_skips_known_and_ignores_work_list(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / 'source'
            source.mkdir()
            known = b'{"queryUrl":"vfc_stats_round_odds2/vf:season:10/1","doc":[]}'
            extra = b'{\n"queryUrl":"vfc_stats_round_odds2/vf:season:10/1","doc":[{"timestamp":123,"data":{"odds":[]}}]\n}'
            metadata = {'competition': 'virtual-football', 'season_ids': ['10', '999'],
                        'discovery_ids': ['10', '20'], 'error': 'Aborted with Ctrl+C'}
            (source / 'known.json').write_bytes(known)
            (source / 'empty.json').write_bytes(extra)
            (source / 'metadata.json').write_text(json.dumps(metadata))
            timing = {'version': 'historical-timing-audit-2.0.0', 'seasons': [
                {'competition': 'virtual-football', 'season_id': s, 'kiotrad': {'present': True},
                 'kiotrad_timing': {'status': 'verified_anchor_available', 'day1_utc_epoch': at}}
                for s, at in [('10', 10000), ('20', 16720)]]}
            (root / 'timing.json').write_text(json.dumps(timing))
            (root / 'imported.json').write_text(json.dumps({'files': [{'sha256': hashlib.sha256(known).hexdigest()}]}))
            before = {p.name: p.read_bytes() for p in source.iterdir()}
            report = audit([source], root / 'timing.json', root / 'imported.json', root / 'output')
            self.assertEqual(report['scan']['already_packaged'], 1)
            self.assertEqual(len(report['ordering_candidates']), 1)
            self.assertEqual(report['ordering_candidates'][0]['season_count'], 2)
            self.assertEqual(report['ordering_candidates'][0]['status'], 'anchored_candidate')
            self.assertTrue(any(p.read_bytes() == extra for p in (root / 'output/files').iterdir()))
            self.assertEqual(before, {p.name: p.read_bytes() for p in source.iterdir()})
            self.assertEqual(report['read_failures'], [])
            manifest = json.loads((root / 'output/manifest.json').read_text())
            for entry in manifest['files']:
                self.assertEqual(hashlib.sha256((root / 'output/files' / entry['file']).read_bytes()).hexdigest(), entry['sha256'])
            self.assertTrue(manifest['complete'])

    def test_wrong_report_version_fails_before_output_creation(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / 'source'
            source.mkdir()
            (root / 'timing.json').write_text('{"version":"old"}')
            with self.assertRaises(ValueError):
                audit([source], root / 'timing.json', root / 'missing.json', root / 'output')
            self.assertFalse((root / 'output').exists())


if __name__ == '__main__':
    unittest.main()
