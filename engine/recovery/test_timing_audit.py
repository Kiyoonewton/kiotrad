import base64
import gzip
import json
import tempfile
import unittest
from pathlib import Path

from timing_audit import assess, scan


class TimingAuditTest(unittest.TestCase):
    def test_holdout_detects_irregular_cadence_and_missing_anchor(self):
        times = {('virtual-euro', '10'): {1: {1000}, 2: {1220}, 3: {1400}},
                 ('virtual-euro', '11'): {2: {1800}}}
        rows = assess({}, times, {})['seasons']
        self.assertEqual(rows[0]['max_interpolation_error_seconds'], 20)
        self.assertEqual(rows[1]['status'], 'day1_anchor_missing')
        self.assertIsNone(rows[1]['max_interpolation_error_seconds'])

    def test_conflicting_captures_are_not_silently_selected(self):
        row = assess({}, {('virtual-euro', '10'): {1: {1000, 1001}}}, {})['seasons'][0]
        self.assertEqual(row['status'], 'conflicting_timing')
        self.assertIsNone(row['day1_utc_epoch'])

    def test_envelopes_deduplicate_and_ignore_response_metadata(self):
        fixture = {'doc': [{'event': 'stats_season_fixtures', '_dob': 999999,
                           'data': {'_id': '10', '_utid': 14149, 'matches': [
                               {'round': 1, 'time': {'uts': 1000}},
                               {'round': 2, 'time': {}},
                           ]}}]}
        odds = {'queryUrl': 'vfc_stats_round_odds2/vf:season:10/1',
                'doc': [{'timestamp': 999999, 'data': {'odds': [
                    {'id': 'vf:match:1', 'tournament_id': 'vf:tournament:14149'}]}}]}
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'fixture.json').write_text(json.dumps(fixture))
            (root / 'envelope.json.gz').write_bytes(gzip.compress(json.dumps({
                'body_base64': base64.b64encode(json.dumps(fixture).encode()).decode()}).encode()))
            (root / 'odds.json').write_text(json.dumps(odds))
            (root / 'bad.json').write_text('invalid')
            before = {p.name: p.read_bytes() for p in root.iterdir()}
            coverage, times, counts = scan([root])
            row = assess(coverage, times, counts)['seasons'][0]
            self.assertEqual(row['day1_utc_epoch'], 1000)
            self.assertEqual(row['verified_days'], 1)
            self.assertEqual(row['combined_odds_match_ids'], 1)
            self.assertEqual(counts['malformed'], 1)
            self.assertEqual(before, {p.name: p.read_bytes() for p in root.iterdir()})


if __name__ == '__main__':
    unittest.main()
