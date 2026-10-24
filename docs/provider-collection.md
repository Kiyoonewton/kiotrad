# Durable provider captures

This increment adds **one-shot collection**, not an unattended worker. It uses
the same independently discovered current season and verified normalization as
`provider:probe`, writing only to KioTrad's configured MongoDB.

From the repository root, initialize/rebuild with `sh engine/start.sh`, then run:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec api php artisan provider:collect
```

For one league append `virtual-football`, `virtual-bundesliga` or `virtual-euro`.
An unsuccessful command exits nonzero; rerun it when connectivity recovers.
Other leagues continue if one league fails. No background polling is enabled.

`provider_captures` stores immutable normalized fixture/odds captures, identified
by a hash of their content including observation time. Retrying the exact capture
does not duplicate it; a new observation remains a new historical record even if
prices do not change. Raw response payloads are excluded. Scheduled UTC times,
unknown actual kickoff, received time, and pre-match verification remain distinct.
Result corrections appear in later captures without rewriting earlier evidence.

`provider_checkpoints` points to the latest durable capture per competition. The
pointer advances only after capture persistence; an interrupted save can leave an
unreferenced capture, never a pointer to an unwritten one. Older retries cannot
move observation time backward. Checkpoints are storage progress, **not** proof
that all season fixtures or all intervening odds were captured.

Remaining work: bounded polling/retry/backoff, season rollover reconciliation
(including the previous season), database integration verification, freshness API
and dashboard connection. This command alone cannot guarantee complete live
history. It creates no strategy signals, bankroll records or bets.
