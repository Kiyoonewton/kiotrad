# KioTrad engineering rules

- KioTrad is independent of `my_trade`. Never connect to its database or volumes.
- Keep reusable provider data separate from versioned strategies and trading records.
- Store UTC timestamps. Display Africa/Lagos; distinguish scheduled, actual and observed times.
- Provider response timestamps are not event kickoff timestamps unless verified.
- No live balance, fixture, signal or profit may be fabricated. Label demo data explicitly.
- Live collection and strategy execution are not implemented in the foundation milestone.
- Use branches and reviewable PRs. Never commit credentials, APP_KEY or raw provider datasets.
- Keep authored source files under 300 lines; split by responsibility.
- Engine, Docker and Compose configuration belongs under `engine/`.
- Test data contracts, unavailable dependencies and decision rules, not implementation details.
- Run frontend lint, tests and build; backend Pint and tests; Docker smoke checks in CI.
- New strategy rules require a new version. Historical signals and settlements are immutable.
