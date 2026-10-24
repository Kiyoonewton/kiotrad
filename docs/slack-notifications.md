# Slack notifications

Alerts run in an optional independent worker. Collectors and paper execution do
not call Slack or depend on this worker. Nothing sends until explicitly enabled
with a valid incoming webhook. Create a Slack app, enable Incoming Webhooks and
authorize the destination channel using Slack's official guide:
https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/

Add privately to `engine/.env` on the server (never paste or commit the URL):

```dotenv
SLACK_NOTIFICATIONS_ENABLED=true
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/REPLACE/IN/PRIVATE
```

After merging and updating the checkout, build/start only this service. Preserve
the existing production MongoDB `--auth` command, private URI and volumes while
updating. The manual authentication edit to `engine/compose.yaml` must not be
discarded to make a Git pull work. Review/resolve that change first.

```bash
docker compose --env-file engine/.env -f engine/compose.yaml --profile notifications build notification-worker
docker compose --env-file engine/.env -f engine/compose.yaml --profile notifications up -d --no-deps notification-worker
docker compose --env-file engine/.env -f engine/compose.yaml logs --tail=30 notification-worker
```

This sends real messages to the authorized channel once enabled. The webhook
chooses the channel; no Slack user-directory access or ChatGPT Slack connection
is needed. Hostname is restricted to hooks.slack.com; redirects are disabled.

Queue one clearly labeled connectivity test:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec -T notification-worker php artisan notifications:watch --once --test
```

The running worker may deliver it first. No real bookmaker bets are placed.

## Alert meanings

| Title | Color | Meaning |
| --- | --- | --- |
| Qualified season | Green | First-attempt future fixture from current live observation strategy; qualification reason and version included |
| Paper stake proposed | Blue | Verified available quote with first-attempt stake and potential gross payout; not an executed entry |
| Paper stake recorded | Blue | Actual committed paper decision, original price, attempt, target and kickoff |
| Paper run settled | Purple | Closed paper run including skipped/limited runs, actual costs, payouts and net |
| Collection interrupted | Orange | Persisted failure/gap or observation more than 60 seconds beyond the collection deadline |
| Collection resumed | Blue | Capture resumed; recovery may still be outstanding |
| Data recovery pending | Yellow | An individual season has at least three unsuccessful repair attempts; next retry shown |
| Data recovery queue complete | Green | Known recovery queue empty after an observed gap; not proof all possible history exists |
| Season recovery verified complete | Green | Previously alerted repair leaves the queue and its latest capture has complete results and verified final standings |
| Market availability unknown | Amber | Missing/unverified odds, never automatically called a confirmed block |
| Market block operator confirmed | Red | Explicit human verification with private evidence checksum |
| Storage capacity alert | Orange | Estimated data exceeds 50/65/75 GiB or evidence filesystem free space falls below 15 GiB |

Strict upcoming qualification/proposal alerts expire at kickoff (or the quote's 300-second
freshness limit). Historical History/API reads do not enqueue alerts; recovered current observations use a dedicated historical-update title and original scheduled event time. The first notification
start timestamp is durable; older paper decisions/closed runs are not replayed
into Slack on first enable. Already-active collection problems can alert then.
Qualification can have no configured paper bankroll: stake is pending. Proposed
stakes are not reservations; overlap, new prices and capital checks still apply
when the paper worker actually decides. Money is NGN; scheduled times use Lagos.

## Reliability and inspection

`notification_outbox` stores immutable payloads with deterministic IDs, delivery
state, retry times and attempt counts. `notification_control` stores startup time,
worker lease, scan failures and sampling times. No new Redis requirement exists.
Inspect health without exposing the webhook:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec -T notification-worker php artisan notifications:status
```

Source scans run about every 15 seconds; delivery is limited to one message per
two seconds. Run one notification worker. Mongo leases protect parallel workers;
crashed delivery leases become retryable after 60 seconds. Slack 429 pauses all
delivery for at least Retry-After. Network/5xx failures retry with bounded backoff;
configuration/4xx failures remain blocked until corrected and explicitly retried:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec -T notification-worker php artisan notifications:watch --once --retry-blocked
```

If Slack accepts a message but the connection/acknowledgement is lost, a retry can
duplicate it: incoming webhooks do not provide exactly-once delivery. Event IDs
identify such duplicates. Whole-server/network outages cannot notify immediately
through the same failed connection; alerts deliver after recovery. External
uptime monitoring is separate work. Never log webhook exceptions or use `config`
output in shared screenshots: it contains expanded secrets.

Storage is sampled hourly and alerts at most once per daily threshold key. Usage
is MongoDB allocated collections/indexes plus evidence file lengths, not a host
disk quota or all Docker usage. The free-space measurement is for the mounted
evidence filesystem; Docker Desktop may differ from the host. No files are deleted.

## Explicit market-block evidence

Only after confirming an actual block, record it with an existing private
evidence file, league, season, day and specific explanation:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec -T collector-english php artisan provider:market-block virtual-football SEASON_ID MATCHDAY --confirmed --evidence=/app/storage/app/private/provider-evidence/FILE --reason="Operator verified the market block"
```

This writes a durable `market_blockages` record; the worker alerts for records
created after its first start. It does not silently drop result/fixture repair
tasks or infer bookmaker intent from an empty response. Automatic provider block
classification remains pending observation of an authentic explicit block signal.

Recovered event-order updates are deduplicated by strategy/version/season/attempt/status and explicitly labeled historical. They never create paper execution records. See `run-simulation.md`.
