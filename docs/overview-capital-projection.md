# One capital projection across the workspace

Overview now contains the former Simulator controls and results. The separate
Simulator navigation item is removed. Overview, Strategies and History share
one selection; changing pages does not reset it. Existing saved Simulator
settings migrate through the same browser storage key.

The base capital is the configured dashboard starting capital when the workspace
opens (currently production uses ₦7,000). It stays pinned during that workspace
session. Every projected monetary value uses:

```
factor = selected initial capital / base initial capital
projected money = base scenario money × factor
```

For a ₦7,000 base, ₦14,000 is ×2 and ₦700 is ×0.1. Other valid whole-naira
inputs need not be exact multiples. Amounts can contain fractional kobo internally;
formatting rounds to two naira decimals. Independently rounded visible rows may
differ slightly from their displayed total. These are proportional projections,
not newly rounded executable stakes.

Capital changes apply immediately in the browser without another capital replay.
Qualification, attempts, allowance, strategy, capital mode and base profit stop
still request a scenario at the fixed base capital. The base profit-stop field is
explicitly in base naira: the projected target scales along with every other money
value. Run choices, closures and the point where a profit stop happens remain those
of the selected base scenario. Increasing projected capital cannot rescue a run
excluded at the base or bypass its calculation limits.

Only numeric monetary `*_minor` fields are scaled, including nested attempts,
allowances, recovery targets, equity, timing comparisons and strategy monetary
rules. Odds, dates, evidence IDs/hashes, counts, attempts, qualification, status and
selection decisions are unchanged. Source objects are copied, never mutated.
The banner on every research page identifies the base and multiplier and labels
the results as projections. Recorded provider evidence, production settings,
monitored benchmark and paper ledger are not written or rescaled.

History now reads the same projected scenario already shown on Overview, rather
than fetching a separate benchmark calculation. It lists the selected scenario's
runs, pages ten seasons at a time and filters by run entry date in Lagos. It does
not replace missing or unqualified opportunities with old benchmark money.
Historical observations remain available in the current-activity and strategy
sections; financial details use the selected shared scenario where present.

Invalid settings hide projected results. While changed rules are calculating,
results from the previous rule selection are not substituted. Evidence refresh
and source availability retain the existing behavior. This is a frontend projection
feature; it does not change strategy or settlement versions or require a database
rebuild. Refresh the local app after rebuilding web; production uses the normal
reviewed web deployment.
