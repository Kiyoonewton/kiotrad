# Continuous capture and feed status

After `sh engine/start.sh`, explicitly enable the three independent collectors:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors up -d --build
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors logs -f collector-english collector-bundesliga collector-euro
```

Each league has one worker process. Successful polls wait 45 seconds after
completion. Failures wait 45, 90, 180, then 300 seconds maximum. The next due
time and failure count persist in `collector_states`, so restarting a worker
does not discard its retry delay. Database failures use a local 45-second delay
until state is accessible again. Other league workers continue independently.
Run only one replica per league; the process lock prevents duplicates within a
container, not across multiple containers or hosts. No distributed deployment
or strategy execution is included.

Stop collectors while leaving the dashboard running:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors stop collector-english collector-bundesliga collector-euro
```

For a single due poll:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec api php artisan provider:watch virtual-football --once
```

The overview refreshes every 30 seconds. It shows the capture's season, the odds
round (not a claimed live matchday), and observation time displayed in Lagos.
Fresh means a capture no older than 120 seconds; stale means older (or a local
clock anomaly); retrying means the worker recorded failures; unavailable means
storage could not be read. A recent capture is not proof of live match status,
current provider prices, or complete history. Bankroll and strategies remain
disabled. Current-season discovery runs each poll. Previous-season final-result
reconciliation and missed-season recovery remain future work: `data_complete`
is explicitly false, and this feed is not yet eligible for strategy decisions.

Original captures remain immutable. Checkpoints are retained when failures occur.
No raw provider payloads or exception details are exposed through the overview.
