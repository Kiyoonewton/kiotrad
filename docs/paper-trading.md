# Shared-capital paper trading

This worker simulates win bets from KioTrad's own stored captures. It never submits bets or accesses bookmaker accounts. Starting money is explicitly configured paper money; no historical profit is imported. Both champion hypotheses keep their observation version; execution freezes policy `paper-win-recovery-1.0.0` on every run.

## Start locally

Merge the Overview fix before this stacked change. Update the branch and rebuild the API and web image. Keep the existing collectors running.

Initialize once, supplying your chosen whole-naira paper capital. The example is illustrative and is not a recommended stake or expected return:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml up -d --build api web

docker compose --env-file engine/.env -f engine/compose.yaml exec api \
  php artisan paper:configure 5471 --target=10942

docker compose --env-file engine/.env -f engine/compose.yaml \
  --profile paper up -d --build paper-worker
```

`paper:configure` cannot reset or top up an existing portfolio. Omit `--target` to leave it unset. Capital is limited to NGN 100–10,000,000 for this pilot. Configuration alone does not run the worker. A target only measures progress; it neither predicts returns nor stops trading automatically.

```bash
# Inspect worker output or stop new paper processing.
docker compose --env-file engine/.env -f engine/compose.yaml logs -f paper-worker
docker compose --env-file engine/.env -f engine/compose.yaml stop paper-worker
```

Stopping pauses settlements too. Reserved money remains reserved until the worker resumes and receives a confirmed result. Do not delete database volumes. The dashboard refreshes every 30 seconds. Inspect `kiotrad.paper_portfolios` (`_id: main`) for the authoritative journal, frozen decisions, active attempts and completed/skipped runs.

## Frozen execution rules

- New runs require monitoring status `watching_fixture`, attempt one, a stable team ID and an upcoming fixture. Starting after missed observations cannot reconstruct a paper trade.
- A verified 1X2 price for that exact fixture must have been received before kickoff and by decision time, with age at most 300 seconds. Later invalid snapshots are not replaced by older valid ones. This is a recorded-price simulation: availability at the source is not proof of bookmaker acceptance, stake limits or payout rules.
- After losing attempts, target round profit is prior actual paper losses plus attempt number × NGN 100. Stake is `ceil((losses + attempt × 100)/(odds - 1))` naira, at least NGN 100. Whole-naira stake rounding and payout rounded down to kobo are explicit; no fees are modeled.
- Draws lose a win bet. Stop on the first win, four losses, insufficient cash, missed entry, or season end. Unconfirmed/cancelled fixtures retain their reservation; no automatic refund or invented settlement. Manual resolution of unavailable results is not implemented.
- A run holds the shared portfolio exclusively, including time between attempts and while waiting for a usable price. Competing runs are recorded as `overlap_skipped`, with no stake, and are not re-entered later. Simultaneous candidates use a fixed lexical league/strategy ordering, not a retrospectively chosen winner.
- On a loss, the next fixture is evaluated on the next worker cycle (15 seconds). If its start was missed, stop rather than backdate a decision. The worker reads MongoDB; it does not increase provider polling.

Cash excludes reserved stakes. Displayed paper balance is cash plus reserved stakes; net changes only after settlement. For example, NGN 200 reserved from NGN 5,471 leaves NGN 5,271 available and NGN 200 committed, with zero settled profit. At odds 1.5, a win credits NGN 300, leaving NGN 5,571 after releasing the reservation.

## Durability and limits

One revision-checked MongoDB document atomically reserves or releases money and appends journal entries. This works on the existing standalone MongoDB without transactions. Concurrent workers cannot both commit the same revision. MongoDB's clock additionally rejects a new reservation if kickoff passes or a price expires while calculations/storage are in flight; synchronize host clocks. Read-only API requests never execute decisions.

Original decisions and completed runs are append-only; settled fields on an active attempt are finalized once. Later provider corrections do not rewrite settled paper history; an explicit adjustment workflow is still needed. New runs pause at 250 completed/skipped runs or 2,000 decisions, keeping the pilot document bounded. Export/archival and additional portfolio creation are future milestones; entries are never silently dropped. Database unavailability shows an unknown balance, not zero. `paper:watch --once` is available for diagnosis.
