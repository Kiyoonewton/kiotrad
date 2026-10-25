# Local-only production data downloads

This workflow replaces continuous remote database reads for Mac development.
Production remains the sole crawler. No server configuration, production Compose
service, MongoDB credential, collector or volume is changed by this feature.

## Separation

| Resource | Production | New local mirror |
| --- | --- | --- |
| Compose project | kiotrad | kiotrad-local |
| MongoDB volume | kiotrad_mongo_data | kiotrad_local_mongo_data |
| Database | kiotrad | kiotrad_local_<sync timestamp> |
| Dashboard | Existing deployment | http://localhost:5174 |
| API | Existing deployment | http://localhost:8001 |
| MongoDB host port | Server loopback 27018 | Mac loopback 27019 |
| Redis | Production instance | Separate local instance |
| Configuration | engine/.env | engine/local/.env.local |

Local Compose deliberately contains no collectors, paper worker or notification
worker. MongoDB destination URI is fixed to the local Compose service, not copied
from the production .env. Slack is disabled. Run this only on the Mac: the command
rejects Linux, remote Docker contexts, wrong project labels and unexpected volumes.

## First download and every later manual refresh

From the updated repository on your Mac, with Docker Desktop running:

```bash
python3 engine/local/sync.py --ssh root@YOUR_SERVER_IP
```

Use an SSH alias instead if preferred. SSH host-key checking remains enabled.
An SSH key/agent is convenient; do not disable host-key checks to bypass a prompt.
Enter the kiotrad_dev read-only MongoDB password when asked. It is used only in
SSH stdin/config, not saved in .env, shell history, command arguments or archives.
The script verifies that this MongoDB account has only read access on kiotrad.

The command creates the private local .env.local and APP_KEY, starts the isolated
local MongoDB, downloads allowlisted provider collections, restores into a new
local database and validates capture references. Only then does it switch the
local configuration and rebuild/start the local API, Redis, web and dashboard
worker. There is a short local restart at the final switch, not a production pause.
Do not run the command concurrently in multiple terminals.

Watch the first local dashboard rebuild:

```bash
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  logs -f --tail=10 dashboard-worker
```

Open http://localhost:5174 after `dashboard: refreshed`. Once the command finishes,
SSH is no longer needed until the next sync. The UI labels the data Local copy
and shows the successful sync time in Lagos. Existing older local services can be
stopped separately; do not use production commands or down -v to clear ports.

## Data and failure behavior

Version one performs a full manual refresh, not incremental change tracking.
It preserves original BSON IDs and types. A new isolated database per refresh
avoids duplicate imports and leaves the previous local database available.
Repeated refreshes do not append duplicate copies to the active database.

Copied collections: collector_states, provider_season_transitions,
provider_catalog_links, market_blockages, season_checkpoints, provider_checkpoints
and provider_captures. Missing optional collections are allowed. Provider captures
are dumped last, after metadata. Checkpoints must resolve to restored captures
before activation. This is a validated research snapshot, not an atomic replica
snapshot: production continues collecting throughout the download.

Accounts, paper portfolios, notification queues and server environment files are
not copied. Raw provider evidence files outside MongoDB are not downloaded;
original permanent evidence remains on production. This is not a substitute for
server backups. Archived normalized captures include the collected matches,
results, prices and their timestamps for reusable strategy research.

A failed SSH transfer/import/validation leaves the active local database and
configuration unchanged. Partial staging databases/archives remain local for
inspection. If service startup fails after switching, the script restores the
previous .env.local and attempts to restart the previous view. Old databases and
compressed downloads under backups/local-sync are retained; disk cleanup is
manual in this first version. Never remove production volumes.

There is no scheduled sync or production download endpoint. Incremental syncing,
retention cleanup and automation can follow after this manual path is verified.
