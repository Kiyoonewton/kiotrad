import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('retention', Path(__file__).with_name('snapshot_retention.py'))
retention = importlib.util.module_from_spec(spec)
spec.loader.exec_module(retention)


class RetentionTests(unittest.TestCase):
    def test_keeps_active_and_previous_and_never_selects_other_databases(self):
        current = 'kiotrad_local_20261026T120000123456Z'
        previous = 'kiotrad_local_20261025T120000123456Z'
        old = 'kiotrad_local_20261024T120000123456Z'
        names = [current, previous, old, 'kiotrad', 'my_trade', 'admin', 'kiotrad_local_restore_check_20261024T120000123456Z']
        self.assertEqual(retention.obsolete(names, current, previous), [old])
