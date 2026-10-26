# Exact fixture odds baseline and qualified-run sizing

Build the persistent research baseline after historical odds recovery:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php artisan provider:build-odds-baseline all
```

Each configured 16-team league has up to 240 directed home/away pairings. Missing price history is not invented. For each pairing, retain independent H/D/A minima across all observed prices, with source capture/match/season IDs, receipt time and pre-match verification flag. No average, recent window or mixing reversed venues is used.

`fixture_odds_minima` contains all-time pairing minima. `fixture_odds_season_minima` retains per-season evidence so historical estimates can exclude the target season and later seasons. These are durable, rebuildable research baselines, separate from raw provider evidence and temporary simulations. `fixture_baseline_progress` records each completed source revision. A rerun skips unchanged seasons; new/recovered odds invalidate the relevant season revision. Run after new data/recovery; it is not an additional Docker collector.

Preview qualifying runs for an explicit stored season:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php artisan strategy:size-qualified virtual-football 3147489 --capital=50000
```

The command refreshes missing/stale baselines and sizes only qualified strategy runs. It uses each selected team's next four known fixtures, whether or not their current markets exist. Each fixture uses the lowest H or A odds from earlier seasons with the exact venue arrangement. No history means no estimate. Windows ending at season day 30 use only the remaining fixtures.

For each whole-naira profit target P, project stake i to recover all previous losses plus i × P, assuming every preceding attempt loses. Round each stake upward to a whole naira, with NGN100 minimum. Choose the largest whole-naira P whose projected total ×2 fits the supplied available capital. Lower profit targets may be necessary for small capital. The output records all assumed odds/evidence and the estimated allowance; the historical minimum and ×2 buffer are not guarantees.

This milestone is a sizing preview: it does not change actual staking, place bets, update a shared balance, reserve overlapping capital or overwrite historical runs. `--capital` is the amount the caller makes available, not an inferred account balance. Freezing the target, the capital input UI, shared capital allocation and settling actual-price runs are the next versioned integration step. Actual odds must remain distinct from historical sizing estimates.
