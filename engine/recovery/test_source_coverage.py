import re
import unittest

from source_coverage import Coverage, compare_sources


def payload(season, prices=True, winner='1'):
    outcomes = [{'id': str(i), 'odds': '2.00', 'result': int(str(i) == winner)} for i in (1, 2, 3)] if prices else []
    return {'doc': [{'data': {'odds': [{'id': 'vf:match:1', 'tournament_id': 'vf:tournament:14149',
                                     'market': [{'id': 1, 'outcome': outcomes}]}]}}]}


def add(coverage, season, prices=True, winner='1'):
    coverage.collect(payload(season, prices, winner), re.fullmatch(r'(\d+)/(\d+)', season + '/1'))


class SourceCoverageTest(unittest.TestCase):
    def test_sources_do_not_leak_and_partial_seasons_remain(self):
        legacy, current = Coverage(), Coverage()
        add(legacy, '10')
        add(current, '11')
        rows = compare_sources(legacy, current)
        self.assertEqual([r['source_presence'] for r in rows], ['legacy_only', 'kiotrad_only'])
        self.assertFalse(rows[0]['kiotrad']['present'])
        self.assertEqual(rows[0]['legacy']['coverage'], 'partial')
        self.assertEqual(rows[0]['legacy']['interruption_cause'], 'not_established')
        self.assertEqual(len(rows[0]['legacy']['missing_or_partial_days']), 30)

    def test_existing_fixture_is_not_existing_odds(self):
        legacy, current = Coverage(), Coverage()
        add(legacy, '10')
        current.collect({'doc': [{'event': 'stats_season_fixtures', 'data': {
            '_id': '10', '_utid': 14149, 'matches': [{'_id': 1, 'round': 1}]}}]}, None)
        row = compare_sources(legacy, current)[0]
        self.assertEqual(row['source_presence'], 'both')
        self.assertEqual(row['shared_odds_match_ids'], 0)
        self.assertEqual(row['legacy_priced_ids_without_kiotrad_price'], 1)
        self.assertEqual(row['kiotrad']['fixture_match_ids'], 1)

    def test_empty_then_recovered_and_conflicting_results(self):
        legacy, current = Coverage(), Coverage()
        add(current, '10', False)
        add(current, '10')
        add(current, '10')
        row = compare_sources(legacy, current)[0]['kiotrad']
        self.assertEqual(row['odds_match_ids'], 1)
        self.assertEqual(row['priced_match_ids'], 1)
        self.assertEqual(row['empty_market_event_responses_by_day'], {1: 1})
        add(current, '10', winner='3')
        row = compare_sources(legacy, current)[0]['kiotrad']
        self.assertEqual(row['conflicting_result_match_ids'], 1)
        self.assertEqual(row['odds_result_match_ids'], 0)


if __name__ == '__main__':
    unittest.main()
