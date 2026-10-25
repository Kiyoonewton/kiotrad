# Non-blocking dashboard snapshots

Enable `DASHBOARD_ASYNC=true` in the private engine/.env. Overview then reads only
local Redis. It never rebuilds historical results or connects to MongoDB in the
HTTP request. `dashboard-worker` builds the complete view every 30 seconds after
the previous refresh finishes. A failed refresh retains the previous good view.
A snapshot older than 90 seconds is visibly stale. No snapshot shows Preparing
dashboard, not invented empty financial results. Redis expiry/eviction causes a
rebuild; snapshot storage is disposable with a seven-day TTL.

The worker needs only read access to MongoDB and write access to local Redis.
It does not collect, send Slack messages, record stakes or alter production data.
Run it locally alongside the API when using the production database over SSH.
Keep local collectors, paper-worker and notification-worker stopped.

Set these private local engine/.env values, keeping the existing URI/password:

```dotenv
DASHBOARD_ASYNC=true
SIMULATION_CACHE_ENABLED=true
REDIS_HOST=redis
REDIS_PORT=6379
MONGODB_SERVER_SELECTION_TIMEOUT_MS=10000
MONGODB_CONNECT_TIMEOUT_MS=10000
MONGODB_SOCKET_TIMEOUT_MS=15000
```

After updating the checkout, with the SSH tunnel open:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  up -d --build --no-deps redis api web dashboard-worker
docker compose --env-file engine/.env -f engine/compose.yaml \
  logs -f --tail=10 dashboard-worker
```

The first refresh can take time over SSH; wait for `dashboard: refreshed`.
Ctrl+C leaves the worker running. A refresh_failed/source_unavailable status
requires checking connectivity; it never replaces a good snapshot with zeros.
The worker uses a Redis lease so multiple workers cannot publish concurrently.
A PHP memory fatal attempts to release its lease using reserved shutdown memory.
An uncatchable kill or Redis failure still leaves a lease that expires after an hour; don't manually flush all Redis
while another rebuild is active. Snapshot keys separate database URI, bankroll
configuration and strategy version, without exposing credentials.

API runs PHP's development server with four workers and --no-reload, keeping
readiness requests independent of a slow explicit History request. This is an
interim container serving arrangement, not a production PHP-FPM deployment.
History pagination still calculates explicitly requested dates; very slow remote
History requests are separate from the non-blocking Overview endpoint.

For production preserve the manual MongoDB --auth command, private engine/.env,
ports and all existing volumes when applying Compose changes. Do not discard
those edits, run down -v or reset the bankroll. Collector services remain separate.
