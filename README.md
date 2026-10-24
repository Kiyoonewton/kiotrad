# KioTrad

**Observe. Test. Track.** Independent virtual football strategy research and live paper-trading monitoring.

This repository is independent of `my_trade`. It has its own MongoDB database and Docker project. It does not import or connect to the existing collector.

## Current milestone

The foundation includes a React/TypeScript dashboard, Laravel API, MongoDB connectivity, two draft champion strategy definitions and GitHub Actions. The dashboard deliberately shows unknown balances and disconnected feeds. Live provider collection, strategy evaluation, bankroll execution and authentication are next milestones, not implemented features.

Provider discovery now includes a one-shot `provider:probe` command: independent current-season discovery, scheduled UTC event times, full-time results and joined pre-match 1X2 prices. It saves private diagnostic files without writing MongoDB or enabling live monitoring. See [the evidence and command instructions](docs/provider-discovery.md).

`provider:collect` adds one-shot immutable normalized captures and per-league
checkpoints in MongoDB. See [collection commands and limits](docs/provider-collection.md).
Unattended polling and dashboard feed integration are still pending.

## Start locally

Requirements: Git, Docker Desktop with Compose v2, and a POSIX shell (macOS Terminal works).

```bash
git clone https://github.com/Kiyoonewton/kiotrad.git
cd kiotrad
git switch feat/project-foundation
sh engine/start.sh
```

The script builds images, creates an ignored `engine/.env`, generates a fresh APP_KEY once and starts the services. Re-running it preserves your key and database.

- Dashboard: http://localhost:5173
- API overview: http://localhost:8000/api/v1/overview
- API liveness: http://localhost:8000/up
- MongoDB readiness: http://localhost:8000/api/v1/ready (503 when unavailable)
- Desktop MongoDB connection: `mongodb://127.0.0.1:27018/kiotrad`

All host ports bind to localhost. MongoDB's host port is 27018 so the old collector can keep using 27017. Compose uses project `kiotrad` and volume `kiotrad_mongo_data`; never point it at `engine_mongo_data`.

Ports can be changed in `engine/.env`. Subsequent commands must pass that file for port interpolation:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml logs -f
docker compose --env-file engine/.env -f engine/compose.yaml down
sh engine/start.sh
```

`down` retains MongoDB. Do not add `-v` unless intentionally deleting this project's data. The foundation uses Laravel's development server and a local unauthenticated MongoDB; it is not a public deployment configuration.

## Development without Docker

Use Node 24, PHP 8.3+, Composer 2, MongoDB 7 and MongoDB PHP extension 2.5+. Copy `apps/api/.env.example` to `apps/api/.env`, then:

```bash
cd apps/api
composer install
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8000
```

In another terminal:

```bash
cd apps/web
npm ci
npm run dev
```

Vite proxies `/api` to localhost:8000. The native API MongoDB URI defaults to localhost:27018; change it to your independent development database if needed. No migrations or seeds are necessary for the read-only foundation.

## Checks

```bash
cd apps/web
npm run lint
npm test
npm run build
```

```bash
cd apps/api
composer lint
composer test
```

CI also builds and starts the full Compose stack, checks the frontend/API proxy and pings real MongoDB through the readiness endpoint. Tests never require provider feeds.

## Layout

- `apps/web`: dashboard and API client.
- `apps/api`: Laravel API, draft strategy catalog, future domain and provider adapters.
- `engine`: all Docker, Compose, reverse-proxy and startup configuration.
- `docs/data-model.md`: shared records, time provenance and strategy/ledger boundaries.
- `docs/roadmap.md`: milestones and acceptance criteria.

Money in API/ledger contracts uses integer kobo; odds use decimal representations. Storage time is UTC, display time Africa/Lagos. Provider response timestamps must not be treated as kickoff without verification.

New strategies will be versioned modules on shared provider data. The two catalog entries currently document the research hypothesis; their monitoring and bankroll flags are disabled until the unresolved rules and live data are verified.
