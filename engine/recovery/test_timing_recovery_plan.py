import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from timing_recovery_plan import build, write_plan


class TimingRecoveryPlanTest(unittest.TestCase):
    def reports(self, ids, anchors, order=None):
        timing = {'version': 'historical-timing-audit-2.0.0', 'seasons': [
            {'competition': 'virtual-euro', 'season_id': s, 'legacy': {'coverage': 'partial', 'odds_match_ids': 8},
             'kiotrad_timing': {'status': 'verified_anchor_available', 'day1_utc_epoch': anchors[s]}
             if s in anchors else {'status': 'day1_anchor_missing'}} for s in ids]}
        discovery = {'version': 'legacy-discovery-evidence-1.0.0', 'read_failures': [], 'ordering_candidates': [
            {'status': 'cadence_mismatch', 'issues': [], 'source_sha256': 'source-one',
             'candidate_oldest_first': order or ids}]}
        return timing, discovery

    def by_season(self, report):
        return {r['season_id']: r for r in report['seasons']}

    def test_later_closure_does_not_erase_earlier_bracketed_agreement(self):
        timing, discovery = self.reports(['1', '2', '3', '4'], {'1': 10000, '3': 23440, '4': 32020})
        report = build(timing, discovery)
        rows = self.by_season(report)
        self.assertEqual(rows['2']['timing_status'], 'bracketed_cadence_hypothesis')
        self.assertEqual(rows['2']['hypothetical_day1_utc'], '1970-01-01T04:38:40+00:00')
        self.assertEqual(report['segments'][1]['observed_gap_seconds'], 8580)
        self.assertEqual(report['segments'][1]['status'], 'longer_than_listed_cadence')
        self.assertEqual(report['segments'][1]['cause'], 'not_established')
        self.assertFalse(any(r['eligible_for_financial_replay'] for r in rows.values()))

    def test_prefix_suffix_and_partial_evidence_are_preserved_without_extrapolation(self):
        timing, discovery = self.reports(['1', '2', '3', '4'], {'2': 10000, '3': 16720})
        rows = self.by_season(build(timing, discovery))
        for season in ['1', '4']:
            self.assertEqual(rows[season]['timing_status'], 'unanchored_extrapolation_required')
            self.assertIsNone(rows[season]['hypothetical_day1_utc'])
            self.assertEqual(rows[season]['legacy_coverage'], 'partial')
            self.assertEqual(rows[season]['legacy_matches'], 8)
        self.assertEqual(rows['2']['recorded_day1_wat'], '1970-01-01T03:46:40+01:00')

    def test_missing_whole_slots_are_not_confirmed_as_closure_or_missing_season(self):
        timing, discovery = self.reports(['1', '2', '3'], {'1': 10000, '3': 30160})
        report = build(timing, discovery)
        self.assertEqual(report['segments'][0]['status'], 'extra_whole_cadence_slots')
        self.assertEqual(self.by_season(report)['2']['timing_status'], 'anchored_break_unresolved')
        self.assertIsNone(self.by_season(report)['2']['hypothetical_day1_utc'])

    def test_wrong_identity_and_nonmonotonic_lists_are_rejected(self):
        timing, discovery = self.reports(['1', '2', '3'], {'1': 10000, '2': 9000, '3': 16720})
        self.assertEqual(build(timing, discovery)['rejected_candidates'][0]['status'], 'anchor_order_conflict')
        timing['seasons'][1]['competition'] = 'virtual-football'
        self.assertEqual(build(timing, discovery)['rejected_candidates'][0]['status'], 'rejected_identity')

    def test_reversed_sequence_is_recomputed_and_conflicting_recorded_days_not_used(self):
        timing, discovery = self.reports(['1', '2', '3'], {'1': 10000, '3': 23440}, ['3', '2', '1'])
        self.assertEqual(self.by_season(build(timing, discovery))['2']['timing_status'], 'bracketed_cadence_hypothesis')
        timing['seasons'][0]['kiotrad_timing']['conflicting_days'] = [2]
        rows = self.by_season(build(timing, discovery))
        self.assertEqual(rows['1']['timing_status'], 'conflicting_recorded_timing')
        self.assertIsNone(rows['1']['recorded_day1_utc'])
        self.assertEqual(rows['2']['timing_status'], 'missing_day1_anchor')

    def test_different_lists_proposing_different_times_are_not_averaged(self):
        timing, discovery = self.reports(['1', '2', '3', '4', '5'], {'1': 10000, '3': 23440, '4': 30000, '5': 43440})
        discovery['ordering_candidates'][0]['candidate_oldest_first'] = ['1', '2', '3']
        discovery['ordering_candidates'].append({'status': 'anchored_candidate', 'source_sha256': 'source-two',
                                               'candidate_oldest_first': ['4', '2', '5']})
        row = self.by_season(build(timing, discovery))['2']
        self.assertEqual(row['timing_status'], 'conflicting_cadence_hypotheses')
        self.assertIsNone(row['hypothetical_day1_utc'])

    def test_duplicate_discovery_files_do_not_count_a_break_twice(self):
        timing, discovery = self.reports(['1', '2'], {'1': 10000, '2': 18520})
        discovery['ordering_candidates'].append({**discovery['ordering_candidates'][0], 'source_sha256': 'source-two'})
        report = build(timing, discovery)
        self.assertEqual(len(report['segments']), 1)
        self.assertEqual(report['segments'][0]['source_sha256s'], ['source-one', 'source-two'])
        self.assertEqual(report['segment_status_counts'], {'longer_than_listed_cadence': 1})

    def test_pinned_inputs_and_no_overwrite(self):
        timing, discovery = self.reports(['1', '2'], {'1': 10000, '2': 16720})
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            t, d = root / 'timing.json', root / 'discovery.json'
            t.write_text(json.dumps(timing))
            discovery['timing_report_sha256'] = hashlib.sha256(t.read_bytes()).hexdigest()
            d.write_text(json.dumps(discovery))
            before = (t.read_bytes(), d.read_bytes())
            report = write_plan(t, d, root / 'output')
            self.assertFalse(report['policy']['applied'])
            self.assertTrue((root / 'output/seasons.csv').is_file())
            self.assertTrue((root / 'output/segments.csv').is_file())
            self.assertEqual(before, (t.read_bytes(), d.read_bytes()))
            with self.assertRaises(FileExistsError):
                write_plan(t, d, root / 'output')
            t.write_text(t.read_text() + '\n')
            with self.assertRaisesRegex(ValueError, 'different timing report'):
                write_plan(t, d, root / 'other')
            self.assertFalse((root / 'other').exists())

    def test_read_failures_and_duplicate_seasons_stop_planning(self):
        timing, discovery = self.reports(['1', '2'], {'1': 10000, '2': 16720})
        discovery['read_failures'] = ['unreadable']
        with self.assertRaises(ValueError):
            build(timing, discovery)
        discovery['read_failures'] = []
        timing['seasons'].append(timing['seasons'][0])
        with self.assertRaises(ValueError):
            build(timing, discovery)


if __name__ == '__main__':
    unittest.main()
