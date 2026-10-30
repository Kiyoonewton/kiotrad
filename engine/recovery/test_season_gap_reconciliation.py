import unittest

from season_gap_reconciliation import reconcile, unique_boundaries
import test_timing_recovery_plan as fixtures
from timing_recovery_plan import build


class SeasonGapReconciliationTest(unittest.TestCase):
    def segment(self, start, end, steps=1):
        return {'from_season': start, 'to_season': end, 'listed_steps': steps}

    def test_known_skipped_season_explains_double_interval(self):
        anchors = {('english', '3147261'): 10000, ('english', '3147289'): 16720, ('english', '3147317'): 23440}
        result = reconcile(self.segment('3147261', '3147317'), 'english', ['3147261', '3147317'], anchors)
        self.assertEqual(result['omitted_recorded_seasons'], ['3147289'])
        self.assertEqual(result['remaining_gap_seconds'], 0)
        self.assertEqual(result['reconciliation_status'], 'known_seasons_omitted')
        self.assertEqual(result['unusual_recorded_boundaries'], [])

    def test_combined_interruption_and_normal_season_keeps_only_original_boundary(self):
        anchors = {('english', '3147661'): 10000, ('english', '3147710'): 27580, ('english', '3147738'): 34300}
        result = reconcile(self.segment('3147661', '3147738'), 'english', ['3147661', '3147738'], anchors)
        self.assertEqual(result['reconciled_steps'], 2)
        self.assertEqual(result['remaining_gap_seconds'], 10860)
        self.assertEqual(result['reconciliation_status'], 'known_seasons_omitted_with_remaining_gap')
        self.assertEqual(len(result['unusual_recorded_boundaries']), 1)
        self.assertEqual(result['unusual_recorded_boundaries'][0]['to_season'], '3147710')
        self.assertEqual(result['unusual_recorded_boundaries'][0]['cause'], 'not_established')

    def test_gap_alone_does_not_invent_a_skipped_season(self):
        anchors = {('english', '10'): 10000, ('english', '30'): 23440, ('euro', '20'): 16720}
        result = reconcile(self.segment('10', '30'), 'english', ['10', '30'], anchors)
        self.assertEqual(result['omitted_recorded_seasons'], [])
        self.assertEqual(result['remaining_gap_seconds'], 6720)
        self.assertEqual(result['reconciliation_status'], 'unexplained_timing_gap')

    def test_order_comes_from_timestamps_and_equal_starts_are_ambiguous(self):
        anchors = {('english', '90'): 10000, ('english', '10'): 16720, ('english', '30'): 23440}
        result = reconcile(self.segment('90', '30'), 'english', ['90', '30'], anchors)
        self.assertEqual(result['omitted_recorded_seasons'], ['10'])
        anchors['english', '20'] = 16720
        result = reconcile(self.segment('90', '30'), 'english', ['90', '30'], anchors)
        self.assertEqual(result['reconciliation_status'], 'ambiguous_recorded_order')
        self.assertIsNone(result['remaining_gap_seconds'])

    def test_multiple_lists_and_combined_gaps_do_not_multiply_boundary_count(self):
        fixture = fixtures.TimingRecoveryPlanTest()
        timing, discovery = fixture.reports(['1', '2', '3'], {'1': 10000, '2': 27580, '3': 34300})
        discovery['ordering_candidates'].append({'status': 'cadence_mismatch', 'source_sha256': 'sparse',
                                               'candidate_oldest_first': ['1', '3']})
        report = build(timing, discovery)
        self.assertEqual(len(report['recorded_boundaries']), 1)
        self.assertEqual(report['recorded_boundaries'][0]['source_sha256s'], ['source-one', 'sparse'])
        self.assertFalse(report['policy']['financial_replay_enabled'])
        self.assertEqual(report['policy']['applied'], False)
        self.assertEqual(unique_boundaries([]), [])

    def test_unanchored_listed_seasons_are_not_silently_removed(self):
        anchors = {('english', '1'): 10000, ('english', '3'): 16720, ('english', '4'): 30160}
        result = reconcile(self.segment('1', '4', 2), 'english', ['1', '2', '4'], anchors)
        self.assertEqual(result['omitted_recorded_seasons'], ['3'])
        self.assertEqual(result['reconciled_steps'], 3)
        self.assertEqual(result['remaining_gap_seconds'], 0)
        # Even an aggregate zero leaves the nonstandard recorded sub-boundary
        # visible; the unknown listed season has not acquired a timestamp.
        self.assertEqual(len(result['unusual_recorded_boundaries']), 1)


if __name__ == '__main__':
    unittest.main()
