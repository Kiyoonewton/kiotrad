# Simulator capital modes and profit stop

Two settings sit beside initial capital, qualification, maximum attempts,
allowance and strategy. Changes recalculate from existing prepared evidence.

- **Compound capital + gains** (default): size the next run from the current
  settled balance. This retains the previous calculation behavior.
- **Fixed entered capital**: size each new run from the entered initial capital,
  without increasing sizing because of accumulated gains. Different fixtures
  and odds can still produce different stakes and per-attempt profit targets.
  Total funds still equal initial capital plus all settled net gains/losses.
  Gains remain available as a buffer against losses; funds are not withdrawn.
  If the frozen sizing plan's reserved allowance exceeds available funds, skip
  the run. Do not reset losses, add money or silently resize the fixed plan.
- **Stop at net profit · ₦**: 0 disables the target. Otherwise, stop opening new
  runs once settled total funds minus initial capital reaches this amount.
  This is cumulative net profit, not payout or per-attempt profit. Finish the
  active run first; its final profit can exceed the target. Unknown results
  cannot trigger the stop. Display the reached target and stop time in WAT.

For a synthetic ₦50,000 initial balance and two consecutive single-attempt wins
at odds 2.00 with allowance ×1, fixed sizing keeps ₦50,000 as its basis and total
funds become ₦150,000; compounding uses ₦100,000 as the second sizing basis and
ends at ₦200,000. These are arithmetic examples, not expected returns.

The total-funds header, net, charts and daily tables use the same settled run
ledger. Run details show both funds at entry and the sizing basis. The target
stops future selection without rewriting source fixtures or adding hypothetical
profits for later opportunities. Each settlement-timing sensitivity scenario
uses the same capital mode and target, so its stop time/allocation may differ.

The API accepts optional `capital_mode=compound|fixed` and integer
`profit_stop=0..10000000` (NGN). Omitted fields retain compounding/no target.
Both settings participate in the scenario cache fingerprint and browser saved
settings. Older browser settings migrate to compounding with no target.
Replay policy/scenario versions are bumped to invalidate older derived results.
The monitored benchmark continues using the default compounding/no-target
behavior. No live bets, production import or strategy qualification changes.
