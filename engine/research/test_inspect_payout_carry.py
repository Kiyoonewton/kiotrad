import contextlib
import copy
import io
import unittest

from inspect_payout_carry import audit_plan, table


class InspectionTest(unittest.TestCase):
    def test_audits_recurrence_payouts_and_totals_and_rejects_tampering(self):
        plan = {"total_minor": 20000, "attempts": [{"odds_scaled": 15000, "actual_odds_scaled": 16000,
            "previous_potential_payout_minor": 0, "stake_minor": 20000, "potential_payout_minor": 32000,
            "total_staked_minor": 20000, "net_if_won_minor": 12000}]}
        audit_plan(plan, 10000)
        for field in ["stake_minor", "potential_payout_minor", "total_staked_minor", "net_if_won_minor"]:
            corrupt = copy.deepcopy(plan)
            corrupt["attempts"][0][field] += 1
            with self.assertRaises(ValueError):
                audit_plan(corrupt, 10000)

    def test_empty_attempts_are_printable_without_inventing_rows(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            table(["Day", "Stake"], [])
        self.assertIn("Stake", output.getvalue())
        self.assertNotIn("NGN", output.getvalue())


if __name__ == "__main__":
    unittest.main()
