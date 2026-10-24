# Event-order research simulations

The research UI uses strategy `0.3.0-event-simulation` and replay policy
`event-order-win-recovery-1.0.0`. Collection delays are data-quality notes, not
missed-entry outcomes. Recovered results are replayed in the original matchday
order, with the same four-attempt windows and stop-at-first-win rule. Current
progress stays pending until source results arrive; a completed window shows its
actual calculated simulation outcome.

Recorded prices are selected deterministically by earliest receipt, never by
which would produce a better outcome. Historical prices received after kickoff
can be used for research, explicitly noting that pre-match availability was not
verified. No odds are invented, same-time conflicts are rejected, fixture identity
and schedule must match, and pricing pauses at missing data. A confirmed operator
market block pauses pricing at that entry. Scheduled times remain distinct from
response/receipt times. Simulated entry times are not recorded actual decisions.

Minimum stake NGN 100, whole-naira stakes, recovery target prior losses plus
attempt × NGN 100; maximum four attempts. Per-run capital defaults to NGN 7,000
(`SIMULATION_CAPITAL_NAIRA`). The existing strict as-observed worker/journal keeps
its original `0.2.0-observation` rules and frozen records; research replay never
rewrites them or reserves money.

## UI and history

Overview, Strategies and History show the same event-order calculations. History
queries `/api/v1/simulations`, newest scheduled event dates first, bounded to 10
seasons/page by default (maximum 25), with both strategy assessments per season.
A Lagos date/range selects seasons whose scheduled events overlap that period.
Unpriced/unknown results are dashes rather than invented zero profit. Older
`entry_closed` journal rows are preserved for audit, not displayed as research
simulation results.

The overview shows cumulative net from **completed independent simulations in
the latest seven days**. Overlapping runs remain independent research scenarios;
the graph is not a single shared-bankroll equity curve or a doubling forecast.
Incomplete runs are counted separately and do not create completed profit.
Historical pages do not send notifications. Recovered current observations send
only clearly labeled `RECOVERED SIMULATION — Historical Update` messages with the
original scheduled event time. Upcoming strict signals retain their own channel
semantics and cannot become historical execution alerts.

Season order uses retained provider catalog adjacency or observed rollovers,
never guessed numeric ID gaps. Catalog links persist beyond the rolling provider
list. An entirely absent season or insufficient metadata cannot be invented;
these cases remain incomplete. No historical API or graph request deletes data.

## Disposable Redis cache

Set `SIMULATION_CACHE_ENABLED=true` and `REDIS_HOST=redis` in the private engine
environment to enable caching. A dedicated Redis 7 service has no published port,
256 MiB key-space budget, 320 MiB container limit, LRU eviction and no persistence.
The API image contains phpredis. The existing host Redis service is not used.

- Recent rolling-week derived views have a seven-day TTL and refresh when source
  checkpoints, season links, block evidence, strategy version or capital changes.
- Requested older seasons/date views have a 24-hour TTL.
- Current progress is also refreshed against a one-minute clock revision when
  no new captures arrive. The recent summary excludes completed events outside
  the moving seven-day window.
- Cache keys include the MongoDB namespace; local development uses its own Redis.
- Redis failure, restart, expiry or eviction rebuilds from durable source data.
  Cache failures do not turn unavailable results into zero profit, affect source
  collection or discard recovery tasks. Redis connections use short timeouts.

MongoDB/raw archives and permanent market-block evidence remain durable. Pending
recovery work stays in MongoDB until verified. Only derived simulation output
belongs in Redis; old journal records are not destructively migrated by this PR.
Preserve production MongoDB `--auth`, private URI and all volumes during rollout.
