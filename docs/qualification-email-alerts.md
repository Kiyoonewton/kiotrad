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

## Email alerts page

The dashboard's **Email alerts** page edits this channel independently of the retrospective simulator. Generate a strong settings key on the server (`openssl rand -hex 32`) and put it in `QUALIFICATION_EMAIL_SETTINGS_TOKEN` in `engine/.env`; enter it in the page to unlock. The key stays in component memory and is cleared on Lock or navigation, never local storage. GET/PUT require this bearer key (minimum 32 characters); an absent key disables this API. Keep the dashboard behind HTTPS and existing operator access controls. Never put the key in a URL.

Editable parameters: alert pause, capital in naira, league, minimum lead seconds, plus independent enable, first-win qualification day and maximum attempts for each strategy. Save explicitly; the next worker scan reads the persisted MongoDB settings. These thresholds are evaluated against current as-observed season evidence, without future results or retrospective cache reuse. Qualification overrides do not change the normal production monitor, paper bankroll or simulator. Maximum attempts is included in the message and monitoring window; this worker still sends one initial alert per run, not a per-attempt betting sequence.

SMTP credentials and recipient stay in env. Changing settings does not resend an already claimed run, enable the env delivery switch or certify SMTP/inbox delivery. Capital is a reference run budget; there are no allowance, compounding or profit-stop controls pretending to manage a bankroll because live stake execution is not implemented.

After adding/changing env values recreate both `api` and `qualification-email-worker` to load configuration. Build `web` to expose the new page. Local snapshots have their own settings and do not copy SMTP secrets from production.

## Stake-plan update

The initial budget-only limitation above is superseded by [Production-only stake plans](alert-stake-plans.md). New alerts wait for a complete conditional plan and verified first quote. The page now supports allowance ×1/×2. Live delivery requires APP_ENV=production, non-mirror mode and a non-local database; local/testing workers cannot send.

## Unified settings page

Email and Slack controls are now together on **Alerts**. See [Unified alert settings](unified-alert-settings.md) for safe migration of different existing capital values, the shared key and the one-record save. Channel credentials and workers remain separate; all qualification parameters are shared after the first reviewed save.
