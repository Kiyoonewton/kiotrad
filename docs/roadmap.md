# Delivery plan

## 1. Foundation — this PR

- React/TypeScript dashboard shell with overview, league filter, strategy descriptions and history empty state.
- Laravel API with read-only draft strategy catalog, liveness and MongoDB readiness.
- Independent Docker project/database, generated local APP_KEY and CI.
- No provider requests, imported research data, automatic signals or paper bets.

## 2. Provider discovery and match lifecycle proof

Observe upcoming → live → settled for one match per league. Identify stable event
IDs, current season discovery, market/outcome identities, scheduled event time,
provider response time and result corrections. Verify whether official standings
and tiebreaks are supplied. Record sample payloads privately with capture times.
Document polling frequency from measured update cadence/provider constraints.

Acceptance: evidence from all three leagues; unknown times explicitly remain unknown.

## 3. Independent collection

Persist shared data and odds snapshots, idempotent writes, retries with backoff,
network failure recovery and restart checkpoints. Add queue/worker only once its
real job exists. Expose freshness, missing matchdays and season readiness to UI.

Acceptance: recover a stopped worker without duplicated observations or lost state.

## 4. Champion strategy evaluator

Implement Next Season and Next Win as separate versioned entry rules.
Confirm champion tie handling, season continuity, and first wins after day 10/no
win cases before enabling either rule. The old research's ten-game trigger cap
must not silently become a new live rule. Signals require pre-result evidence.

Acceptance: tested chronological replay with no future information and explainable signals.

## 5. Paper portfolio and monitoring

Four attempts, minimum NGN 100, prior losses + attempt × NGN 100 profit target.
Use integer kobo for ledger amounts and decimal calculations for prices/payouts;
explicit stake increment and payout rounding policies. One active run, recorded
conflicts, capital reservation, settlement idempotency and reconciliation.

Acceptance: losing cycles and provider corrections handled; shared bankroll reconciles.

## 6. Dashboard completion and hosting

Real capital curve, goals, run details, overlap timeline, strategy dropdown,
versioned comparisons and live paper result separation. Add authentication,
production API server, HTTPS, authenticated MongoDB, backup restore test, alerts
and retention policies before exposing the service publicly.

Acceptance: host runs without the user's Mac, supports rollback and survives feed outages.

## Adding strategies

Research in `my_trade` → explicit versioned rule → repeatable replay → monitored
signals → paper portfolio eligibility. No ongoing import or connection to that
repository is required. New data requirements extend shared schemas additively;
do not change earlier rule versions or settled ledger entries.
