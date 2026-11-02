# Capital calculations across the workspace

The current workspace selects versioned retrospective payout-carry research; see
[payout-carry-research.md](payout-carry-research.md) for the recurrence, separate
strategy controls and real-evidence inspection command.

Overview, Strategies and History share the scenario requested with the entered
capital, each strategy’s qualification day and attempts, global allowance,
strategy/league filter, capital mode and profit stop. All money is returned by the financial replay; the frontend no
longer multiplies a ₦7,000 baseline.

This replaces the earlier proportional display projection. Minimum stakes and
whole-naira rounding can change affordability, candidate selection and subsequent
overlap decisions, so multiplying an old balance cannot certify a larger-capital
run. The minimum stake remains ₦100; strategy definitions are not scaled.

Fixed mode sizes each opportunity with the entered initial capital, subject to
the available balance. Reinvest mode sizes with the updated balance. The profit
stop is expressed in actual naira and checked against settled net profit.

## Performance and freshness

Input edits are debounced for 400 milliseconds. Scenario requests reuse prepared
fixtures, odds and qualification evidence; they never reconstruct raw evidence or
query MongoDB. The existing backend result cache keys each scenario by all
settings, source revision and calculation versions. Repeat settings reuse a
cached result while evidence changes invalidate it.

A changed selection hides the old amounts until its result arrives. Aborted or
late responses cannot replace the current selection. Page navigation reuses the
same result. Background refresh failures retain only the last result matching
the current settings.

Browser preferences retain their entered capital. Existing saved profit targets
are now interpreted literally in naira, as labelled by the control. The
production configured bankroll, source evidence and historical paper ledger are
unchanged. The legacy recovery and new payout-carry research models remain explicitly versioned.
