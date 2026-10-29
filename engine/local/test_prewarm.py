import subprocess
import unittest
from types import SimpleNamespace
from unittest.mock import Mock

from prewarm import prepare


class PrewarmTests(unittest.TestCase):
    def test_staging_views_are_prepared_without_stopping_active_services(self):
        compose = Mock()
        prepare(SimpleNamespace(compose=compose), 'kiotrad_local_20261029T000000Z', 5000000)
        calls = [c.args for c in compose.call_args_list]
        self.assertEqual(calls[0], ('up', '-d', 'redis'))
        self.assertIn('MONGODB_DATABASE=kiotrad_local_20261029T000000Z', calls[1])
        self.assertIn('SIMULATION_CAPITAL_NAIRA=50000', calls[1])
        self.assertEqual(calls[1][-3:], ('artisan', 'dashboard:watch', '--once'))
        self.assertFalse(any('stop' in call for call in calls))

    def test_preparation_error_propagates_before_caller_can_activate(self):
        compose = Mock(side_effect=[None, RuntimeError('preparation failed')])
        activate = Mock()
        with self.assertRaises(RuntimeError):
            prepare(SimpleNamespace(compose=compose), 'kiotrad_local_20261029T000000Z', 5000000)
            activate()
        activate.assert_not_called()

    def test_production_or_invalid_budget_rejected_without_docker(self):
        for database, capital in [('kiotrad', 5000000), ('kiotrad_local_20261029T000000Z', 9999)]:
            compose = Mock()
            with self.assertRaises(RuntimeError):
                prepare(SimpleNamespace(compose=compose), database, capital)
            compose.assert_not_called()


if __name__ == '__main__':
    unittest.main()
