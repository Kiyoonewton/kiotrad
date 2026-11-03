# Qualification emails

Optional production worker for manual review. No bets, deposits, withdrawals or strategy-setting writes. The default **₦50,000 is a reference total run budget, not a stake**. Production qualification rules apply; changing retrospective UI settings does not change this worker.

It scans current recorded monitoring, selects the first upcoming attempt of a qualified run and rechecks collection freshness (60 seconds), scheduled fixture identity and a pre-kickoff send deadline. Default scan pause is 5 seconds after each scan; scan execution adds latency. By default, no new submission starts within 10 seconds of scheduled kickoff. Bookmaker cutoff may be earlier. Gmail inbox delivery is not guaranteed; the historically modeled 19-second between-attempt gap can be too short for email/manual entry. Never chase an expired alert.

The email includes team, selection, fixture, matchday, strategy/version, qualification day and scheduled WAT start. Live stake instructions are omitted: the retrospective payout-carry sizing requires future actual odds. A causal staking plan remains separate work before this can supply executable stakes for a ₦50,000 run.

## Configure after merge

Edit production `engine/.env` locally on the server. Do not paste passwords into chat or commit them:

```dotenv
QUALIFICATION_EMAIL_ENABLED=true
QUALIFICATION_EMAIL_TO=your-recipient-address
QUALIFICATION_EMAIL_CAPITAL_MINOR=5000000
QUALIFICATION_EMAIL_LEAD_SECONDS=10
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=your-sending-gmail-address
MAIL_PASSWORD=your-google-app-password
MAIL_FROM_ADDRESS=your-sending-gmail-address
MAIL_FROM_NAME=KioTrad
```

Google app passwords require eligible account configuration; use Google's own instructions: https://support.google.com/accounts/answer/185833 . An existing ChatGPT Gmail connection does not configure production SMTP. SMTP host/port documentation: https://developers.google.com/workspace/gmail/imap/imap-smtp .

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  --profile email-alerts up -d --build --no-deps qualification-email-worker

docker compose --env-file engine/.env -f engine/compose.yaml \
  logs -f --tail=20 qualification-email-worker
```

Existing MongoDB must be running. `disabled_or_unconfigured` means no delivery has been enabled. `submitted:N` means SMTP accepted N submissions, not verified inbox arrival. `waiting` includes no eligible opportunity or an already handled run.

Each league/season/strategy/version has an insert-only record in `qualification_email_alerts`. Concurrent workers cannot claim the same new identity. Ambiguous SMTP failures become `delivery_uncertain` and are not retried automatically, preventing duplicate or stale betting prompts; interrupted `sending` records also remain deduplicated. Inspect these statuses before any manual delivery decision. No historical alerts are backfilled. This uses a dedicated collection and leaves Slack alerts unchanged.

To stop: `docker compose --env-file engine/.env -f engine/compose.yaml stop qualification-email-worker`.
