# Production-only qualification stake plans

Slack and email qualified alerts now include a stake per selected attempt, fixture/matchday, scheduled WAT time, historical estimated odds, the verified first quote, planned total, frozen target and allowance. Capital comes from the channel's saved settings, not a hardcoded ₦100,000. Allowance defaults to ×1 and is editable to ×2.

## Production guard

Delivery is blocked before any opportunity/storage/planner/transport work unless **APP_ENV=production**, `LOCAL_DATA_MIRROR` is false, and the database is nonempty and not named `kiotrad_local*`. Local and testing workers return `production_only`, even if credentials and settings are copied. The settings page shows a production-only message. Do not change your local environment to production; set APP_ENV only in the production server's `engine/.env`.

## Causal calculation

Version: `entry-frozen-estimated-carry-1.0.0`. This is distinct from retrospective payout carry.

1. Read the selected future schedule from captures stored by the decision time. Require the full selected attempt window and a known day-one season opening; never invent missing fixtures or append slots past matchday 30.
2. For each exact home/away fixture and team selection, find the lowest indexed earlier-season odds whose underlying capture was already stored by the decision time. Exclude the current season and future observations. Missing evidence leaves the plan pending; the hot path does not rebuild all history.
3. Require a fresh, verified pre-match quote for the first fixture (at most 60 seconds old). Match fixture ID, teams and scheduled time; reject a quote below the historical estimate.
4. Compute the ₦100 baseline with whole-naira stakes: first stake = ceil(100 / (estimated odds − 1)); each next stake = ceil((previous potential payout + 100) / (estimated odds − 1)). Minimum stake remains ₦100. The first potential payout uses the current first quote; later potential payouts use the corresponding historical estimates because later actual odds are unknown.
5. Target = floor-kobo(100 × configured capital / (allowance × baseline total)). Recompute the recurrence with whole-naira rounding. Reduce the target if rounding would exceed capital. Require planned total × allowance ≤ configured capital.
6. Freeze the resulting conditional stakes in the delivered plan. Stop at first win. Do not increase later stakes just because an earlier actual quote was higher. This explicitly differs from recalculating future stakes using actual payouts not yet known at entry.

Historical minima are not guaranteed lower bounds. A later row is conditional: verify current odds are at least its estimate, market still accepts the bet and funds are available. Otherwise stop/reassess. No bookmaker balance verification or capital reservation is performed. Concurrent alerts can each quote the configured capital; do not treat that as permission to fund overlapping full-budget runs. Potential payout/net is illustrative, not booked profit or guaranteed recovery.

The plan, schedule capture IDs, baseline source IDs and first quote evidence are pinned to the channel's insert-only alert record. Already delivered runs are not resent after changing capital or upgrading. Both channels share the same policy while keeping independent settings and delivery identities.

## Missing inputs and timing

Without all required inputs the worker logs `waiting_for_stake_inputs:N`; it does not claim/send a misleading budget-only alert. It retries on later scans until the qualification deadline. If inputs never become available before the deadline, that opportunity is missed. Collection freshness, final quote freshness and deadline are rechecked before submission. Inbox/webhook delivery and bookmaker cutoffs are still not guaranteed.

## Deploy after merge

On production only, ensure `APP_ENV=production` in `engine/.env`. Keep channel credentials and enabled flags configured. Rebuild API, dashboard worker and active alert workers, then recreate web so its API address refreshes. Example for both channels:

```bash
git pull --ff-only --no-rebase origin main &&
docker compose --env-file engine/.env -f engine/compose.yaml \
  --profile email-alerts --profile slack-alerts \
  up -d --build --force-recreate --no-deps \
  api dashboard-worker qualification-email-worker qualification-slack-worker web
```

Inspect logs for `production_only`, `waiting_for_stake_inputs:N`, `submitted:N`, `paused` or `disabled_or_unconfigured`. Verify the next new qualified alert contains the saved capital and stake rows; no historical catch-up messages are sent. The production guard does not stop pre-existing unrelated operational Slack workers.
