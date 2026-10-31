import copy
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from older_timing_proposals import build, write_proposals


class OlderTimingProposalsTest(unittest.TestCase):
    def reports(self):
        timing = {'version': 'historical-timing-audit-2.0.0', 'seasons': [
            {'competition': 'virtual-euro', 'season_id': s,
             'legacy': {'coverage': 'partial', 'odds_match_ids': 8},
             'kiotrad_timing': {'status': 'verified_anchor_available', 'day1_utc_epoch': at}
             if at else {'status': 'day1_anchor_missing'}}
            for s, at in [('100', None), ('200', None), ('300', 1792306554), ('900', 1792313274)]]}
        discovery = {'version': 'legacy-discovery-evidence-1.0.0', 'read_failures': [],
                     'ordering_candidates': [self.candidate(['100', '200', '300', '900'])]}
        return timing, discovery

    def candidate(self, ids, digest='a' * 64):
        return {'status': 'anchored_candidate', 'issues': [], 'source_sha256': digest,
                'candidate_oldest_first': ids}

    def rows(self, timing, discovery):
        return {r['season_id']: r for r in build(timing, discovery)['seasons']}

    def test_prefix_backdates_by_list_steps_and_keeps_partial_season(self):
        timing, discovery = self.reports()
        before = copy.deepcopy((timing, discovery))
        rows = self.rows(timing, discovery)
        self.assertEqual(rows['100']['status'], 'estimated_unbounded')
        self.assertEqual(rows['100']['estimated_day1_utc'], '2026-10-18T03:11:54+00:00')
        self.assertEqual(rows['100']['estimated_day1_wat'], '2026-10-18T04:11:54+01:00')
        self.assertEqual(rows['100']['legacy_coverage'], 'partial')
        self.assertEqual(rows['100']['legacy_matches'], 8)
        self.assertIsNone(rows['100']['recorded_day1_utc'])
        self.assertEqual(rows['300']['status'], 'recorded')
        self.assertIsNone(rows['300']['estimated_day1_utc'])
        self.assertFalse(any(r['eligible_for_financial_replay'] for r in rows.values()))
        self.assertEqual((timing, discovery), before)

    def test_sparse_calibration_uses_known_recorded_anchors(self):
        timing, discovery = self.reports()
        timing['seasons'].append({'competition': 'virtual-euro', 'season_id': '800',
                                 'kiotrad_timing': {'status': 'verified_anchor_available',
                                                  'day1_utc_epoch': 1792319994}})
        discovery['ordering_candidates'][0]['candidate_oldest_first'] = ['100', '200', '300', '800']
        row = self.rows(timing, discovery)['100']
        self.assertEqual(row['status'], 'estimated_unbounded')
        self.assertEqual(row['proposals'][0]['calibration_seasons'], ['300', '900', '800'])

    def test_nearest_closure_blocks_prefix_but_later_closure_does_not(self):
        timing, discovery = self.reports()
        timing['seasons'][-1]['kiotrad_timing']['day1_utc_epoch'] += 1800
        row = self.rows(timing, discovery)['100']
        self.assertEqual(row['status'], 'blocked')
        self.assertIsNone(row['estimated_day1_utc'])
        timing, discovery = self.reports()
        timing['seasons'].append({'competition': 'virtual-euro', 'season_id': '999',
                                 'kiotrad_timing': {'status': 'verified_anchor_available',
                                                  'day1_utc_epoch': 1792321794}})
        discovery['ordering_candidates'][0]['candidate_oldest_first'].append('999')
        self.assertEqual(self.rows(timing, discovery)['100']['status'], 'estimated_unbounded')

    def test_conflicting_prefix_lists_never_pick_one_or_average(self):
        timing, discovery = self.reports()
        discovery['ordering_candidates'].append(self.candidate(['100', '300', '900'], 'b' * 64))
        row = self.rows(timing, discovery)['100']
        self.assertEqual(row['status'], 'conflicting')
        self.assertIsNone(row['estimated_day1_utc'])
        self.assertEqual(len(row['proposals']), 2)

    def test_reverse_lists_and_repeated_sources_are_not_confirmation(self):
        timing, discovery = self.reports()
        candidate = discovery['ordering_candidates'][0]
        candidate['candidate_oldest_first'].reverse()
        discovery['ordering_candidates'].append(copy.deepcopy(candidate))
        row = self.rows(timing, discovery)['100']
        self.assertEqual(row['status'], 'estimated_unbounded')
        self.assertIn('unknown', row['uncertainty'])

    def test_sparse_prefix_cannot_jump_past_earlier_recorded_anchor(self):
        timing, discovery = self.reports()
        timing['seasons'].append({'competition': 'virtual-euro', 'season_id': '999',
                                 'kiotrad_timing': {'status': 'verified_anchor_available',
                                                  'day1_utc_epoch': 1792319994}})
        discovery['ordering_candidates'][0]['candidate_oldest_first'] = ['100', '900', '999']
        row = self.rows(timing, discovery)['100']
        self.assertEqual(row['status'], 'blocked')
        self.assertEqual(row['blocked_evidence'][0]['reason'], 'prefix_anchor_is_not_earliest_recorded_season')

    def test_conflicting_recorded_timing_is_not_estimated(self):
        timing, discovery = self.reports()
        timing['seasons'][0]['kiotrad_timing'] = {'status': 'conflicting_timing', 'conflicting_days': [1]}
        self.assertEqual(self.rows(timing, discovery)['100']['status'], 'conflicting')

    def test_bad_identity_or_missing_provenance_never_estimates(self):
        timing, discovery = self.reports()
        timing['seasons'][0]['competition'] = 'virtual-football'
        self.assertEqual(self.rows(timing, discovery)['100']['status'], 'unavailable')
        timing, discovery = self.reports()
        discovery['ordering_candidates'][0]['source_sha256'] = None
        with self.assertRaisesRegex(ValueError, 'provenance'):
            build(timing, discovery)

    def test_pinned_inputs_new_output_and_read_failures(self):
        timing, discovery = self.reports()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            t, d = root / 'timing.json', root / 'discovery.json'
            t.write_text(json.dumps(timing))
            discovery['timing_report_sha256'] = hashlib.sha256(t.read_bytes()).hexdigest()
            d.write_text(json.dumps(discovery))
            report = write_proposals(t, d, root / 'output')
            self.assertFalse(report['policy']['applied'])
            self.assertTrue((root / 'output/seasons.csv').is_file())
            with self.assertRaises(FileExistsError):
                write_proposals(t, d, root / 'output')
            t.write_text(t.read_text() + '\n')
            with self.assertRaisesRegex(ValueError, 'different timing'):
                write_proposals(t, d, root / 'other')
            self.assertFalse((root / 'other').exists())
        discovery['read_failures'] = ['unreadable']
        with self.assertRaises(ValueError):
            build(timing, discovery)


if __name__ == '__main__':
    unittest.main()
