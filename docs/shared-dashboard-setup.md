# Shared-capital dashboard setup

The overview now receives `shared_simulation` built by the dashboard worker. It uses the versioned one-active-run replay, proportional profit per attempt frozen at entry, actual recorded prices, double-cost allowance and recorded result-confirmation release times. No real bets are placed. Independent research remains a separate API field; it is not substituted when the shared calculation is unavailable.

## What was missing

The previous UI consumed `research_simulation`, whose independent run replay targets ₦100 per attempt. Neither running `strategy:size-qualified` nor `strategy:replay-shared` updated that UI. The overview and current strategy cards now consume shared-run outcomes and frozen profit targets instead. Skips do not add profit. Missing prices, schedules, fixture history and confirmation timing are shown as unresolved reasons; they are not repaired by invented quotes or an assumed match duration.

The shared worker refresh automatically builds/updates fixture baselines. A separate baseline command is optional diagnostics, not a prerequisite. Redis caches raw team evidence per season source revision; new capital replays the portfolio without re-reading unchanged season bodies. Source/checkpoint changes invalidate the cached overview. Research source reads and initial baseline construction can still be expensive on the first refresh.

## Set the local starting capital once

Run from the repo root on the Mac after pulling the merged change:

```sh
python3 - <<'PY'
from pathlib import Path
p = Path('engine/local/.env.local')
lines = [line for line in p.read_text().splitlines() if not line.startswith('SIMULATION_CAPITAL_NAIRA=')]
p.write_text('\n'.join(lines) + '\nSIMULATION_CAPITAL_NAIRA=50000\n')
PY

docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  up -d --build --no-deps redis api web dashboard-worker

docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  logs -f --tail=20 dashboard-worker
```

Wait for `dashboard: refreshed`. Do not run `paper:configure` to change this research replay: it initializes a different paper ledger and does not reset an existing one. Do not flush all Redis or delete source data. The dashboard snapshot key changes with this release, avoiding a stale old-model snapshot.

The same setting can be applied on production by replacing the env path with `engine/.env` and the compose path with `engine/compose.yaml`. Recreate API and dashboard-worker together so they read the same capital. Production collectors and MongoDB do not need restarting for this dashboard change.

Local sync intentionally imports production strategy settings, including capital. If production still has 7000, the next sync imports 7000 again. Set 50000 on production too if both should match. Source strategy hashes now cover the Research policy classes as well as the original strategy files; align production API code before the next fresh raw sync.

## Diagnostics only, if needed

```sh
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  exec -T api php -d memory_limit=512M artisan strategy:replay-shared --capital=50000 \
  > /tmp/kiotrad-shared-capital.json
```

Inspect `runs`, `excluded`, `reserved_allowance_minor` and `timing_basis`. Recorded archive confirmation times can be much later than match completion. That uncertainty can hold a run open and conservatively skip later candidates. The UI does not label these as proof of actual simultaneous betting opportunities.

If actual historical odds are missing, recovery must fetch genuine raw odds on production before the next sync; running a baseline builder cannot reconstruct absent prices:

```sh
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php -d memory_limit=512M artisan provider:recover-odds-all all
```

This is a conditional repair, not a command to run for every dashboard rebuild. Unavailable provider history remains unresolved. The first stored seasons may also have insufficient earlier exact-fixture price history for a look-ahead-free sizing estimate.


## Consistent card and history pricing

The integration is deliberately restored after the earlier revert. `SharedRunCalculation` renders the same stored-in-cache shared run on current strategy cards and history rows. History joins the cached portfolio by league, season and strategy; it clears independent financial fields and never recomputes a different target for a filtered page or league. A warming portfolio shows a refreshing state rather than old ₦100 figures. HTTP history reads do not trigger full portfolio reconstruction.

Regression coverage uses synthetic four-attempt historical floor odds of 2.00 and ₦50,000 capital: the frozen per-attempt target is ₦961. If the first actual price is 1.35 and wins, stake is ₦2,746, payout ₦3,707.10 and net ₦961.10. This demonstrates the formula; the actual target for Borussia M’gladbach depends on its own four historical fixture floors, so ₦961 is not asserted as that real run’s target.
