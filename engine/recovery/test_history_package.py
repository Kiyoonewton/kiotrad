import json
import tempfile
import unittest
from pathlib import Path

from package_legacy_history import package
from verify_history_package import verify


def write(root, season, priced=True, price='2.00'):
    outcomes = [{'id': str(i), 'odds': price, 'result': int(i == 1)} for i in (1, 2, 3)] if priced else []
    data = {'queryUrl': 'vfc_stats_round_odds2/vf:season:' + season + '/1', 'doc': [
        {'data': {'odds': [{'id': 'vf:match:' + season, 'tournament_id': 'vf:tournament:14149',
                           'market': [{'id': 1, 'outcome': outcomes}]}]}}]}
    path = root / (season + '.json')
    path.write_text(json.dumps(data))
    return path


class HistoryPackageTest(unittest.TestCase):
    def test_new_partial_and_overlap_preserved_exactly(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            old, current, dest = root/'old', root/'current', root/'package'
            old.mkdir(); current.mkdir()
            write(old, '10', False)
            original = write(old, '11').read_bytes()
            write(old, '12')
            write(current, '12', price='3.00')
            report = package(old, current, dest)
            rows = {r['season_id']: r for r in report['seasons']}
            self.assertEqual(rows['10']['legacy']['priced_match_ids'], 0)
            self.assertEqual(rows['10']['legacy']['coverage'], 'partial')
            self.assertEqual(rows['11']['next_step'], 'new_history_parser_required')
            self.assertEqual(len(rows['12']['price_comparison']['different_recorded_prices']), 1)
            self.assertFalse(report['applied'])
            self.assertTrue(any(p.read_bytes() == original for p in (dest/'files').iterdir()))
            self.assertEqual(verify(dest)['verified_files'], 3)
            first = next((dest/'files').iterdir())
            first.write_text('tampered')
            with self.assertRaisesRegex(ValueError, 'checksum'):
                verify(dest)

    def test_existing_destination_is_never_overwritten(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            with self.assertRaises(ValueError):
                package(root, root, root)

    def test_missing_price_and_matching_price_are_distinct(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            old, current = root/'old', root/'current'
            old.mkdir(); current.mkdir()
            write(old, '10'); write(current, '10', False)
            write(old, '11'); write(current, '11')
            rows = {r['season_id']: r for r in package(old, current, root/'package')['seasons']}
            self.assertEqual(len(rows['10']['price_comparison']['missing_prices']), 1)
            self.assertEqual(len(rows['11']['price_comparison']['matching_prices']), 1)
            self.assertEqual(len(rows['11']['price_comparison']['missing_prices']), 0)


if __name__ == '__main__':
    unittest.main()
