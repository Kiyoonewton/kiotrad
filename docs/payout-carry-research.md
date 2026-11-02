# Payout-carry research, version 1

This is retrospective sizing, not an executable-at-entry strategy. Its initial
profit target depends on recorded actual odds for every fixture in the selected
window, including fixtures after an early win. Outcomes do not size or select
plans. Incomplete windows or missing actual/estimated odds cannot produce a
complete plan; they remain visible as exclusions or unresolved opportunities.

## Settings and calculation

Global: entered capital, fixed/reinvest mode, allowance x1/x2, settled net-profit
stop, and the current betting minimum of NGN 100. Each strategy has separate
qualification (1–30) and attempts (1–10) controls on Strategies. One shared
bankroll is counted once on Overview; overlapping runs still compete for it.

For each selected run, E is its earlier-season exact-venue/outcome historical
minimum, A is its recorded actual price, and P starts at NGN 100. Stakes round
up to whole naira and stay at least NGN 100. Potential payouts round down to kobo.

- First stake = ceil(P / (E1 - 1)).
- Subsequent stake = ceil((previous stake x previous A + P) / (current E - 1)).
- Baseline cost C100 = sum of that run's entire selected stake window.
- Sizing capital K = initial capital in fixed mode, or current balance in reinvest mode.
- Target P = floor to kobo(100 x K / (allowance x C100)).
- Recalculate the entire recurrence at P; do not merely multiply rounded stakes.
- If re-rounding exceeds K / allowance, reduce P with bounded binary search.
- Reserve total planned stakes x allowance; reject if current balance cannot cover it.
- Place pinned planned stakes, use actual odds for payouts, stop on the first
  win, attempt limit, market/price issue or existing timing guard.
- Net = booked payout minus stakes actually placed. Potential payout is not booked profit.

The baseline is per run, never hardcoded to NGN 5,921 or any other example.
There is no NGN 7,000/30,000 display multiplier or capital ceiling beyond the
existing validated NGN 100–10,000,000 request range. Selected amounts are
calculated and cached; entered capital is sent unchanged to the financial API.

The UI requests `payout_carry_v1`. Legacy API calls default to the separately
versioned `recovery` model. Saved paper ledgers and provider source data are not
rewritten. The model and all strategy/global settings participate in the result
cache fingerprint alongside source and calculation versions. Prepared evidence
is reused; requests never initiate raw reconstruction or MongoDB reads.

## Inspect a real stored run after deployment

Deploy matching API/worker code on production before a new raw sync: strategy
snapshot verification deliberately checks all domain files. To test an existing
local snapshot, rebuild API, worker and web with the local compose file;
existing prepared evidence is reused and no fresh raw sync is required.

From the repository root on the Mac:

```bash
python3 -u engine/research/inspect_payout_carry.py --capital 30000 \
  --next-win-qualification 5 --next-win-attempts 4 \
  --next-season-qualification 5 --next-season-attempts 5
```

The command compares allowance x1 and x2 using the same evidence revision. It
selects a genuine common planned run (preferring at least two placed attempts),
prints the NGN 100 baseline, exact factor, re-rounded full plan, actually placed
attempts, bankroll totals and source IDs. Use `--run-id` to inspect a specific
opportunity. `--mode compound` selects reinvestment; `--profit-stop`, `--capital`
and the strategy flags reproduce other selections.

No fixtures, odds or wins are invented if no comparable run is available. The
command verifies plan arithmetic, allowance and placed stakes/net before saying
PASS. That does not certify provider evidence, entry-time availability or
modeled event finish. HTTP requests may populate disposable result caches;
source evidence, production settings and paper ledgers are not written.
