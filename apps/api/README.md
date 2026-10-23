# KioTrad API

Laravel 12 with the official MongoDB integration. See the repository README for startup and checks, and `docs/` for the data contract and roadmap.

Read-only foundation endpoints:

- `GET /api/v1/overview`: league configuration, draft strategy definitions and empty portfolio state.
- `GET /api/v1/ready`: real MongoDB ping; 503 without driver details on failure.
- `GET /up`: application liveness independent of database availability.

No provider collector, strategy execution or trading writes are enabled yet.
