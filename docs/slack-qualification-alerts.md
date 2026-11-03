# Configurable Slack qualification alerts

Slack has the same manual-review qualification controls as Email alerts: enable/pause, default ₦50,000 reference total-run budget, league, lead time and per-strategy qualification/maximum attempts. The new **Slack alerts** page stores settings independently of email and simulator settings. It sends one initial alert per run, not automatic bets or follow-up stakes. No live stake sizing is claimed.

Configure production `engine/.env` privately:

```dotenv
SLACK_NOTIFICATIONS_ENABLED=true
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/your-private-webhook
QUALIFICATION_SLACK_ENABLED=true
QUALIFICATION_SLACK_SETTINGS_TOKEN=your-generated-long-settings-key
```

Generate a settings key with `openssl rand -hex 32`. Enter it on Slack alerts. The webhook is a secret and is never returned to the UI. If the Slack settings key is omitted entirely, the email settings key is the fallback; an explicitly empty Slack key disables settings access. Gmail/SMTP is not required.

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  --profile slack-alerts up -d --build --force-recreate --no-deps \
  api web dashboard-worker qualification-slack-worker
```

Existing data services must be running. Restart web after a later API recreation if Nginx retains an outdated upstream address. Recreate the old `notification-worker` too if it is enabled, so it loads qualification suppression:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  --profile notifications up -d --build --force-recreate --no-deps notification-worker
```

With `QUALIFICATION_SLACK_ENABLED=true`, legacy qualification and quote production/delivery are suppressed; other operational/paper Slack notifications continue. This prevents the two paths from sending competing qualification prompts. If the new worker is not running, these prompts will not be delivered by the old worker.

Both channels use the same fresh-data, as-observed qualification and expiry checks. Slack has its own settings and delivery collections, so a sent email does not suppress Slack. Run/version deduplication survives restarts. Transport failure is marked delivery_uncertain without automatic resend, including rate limits; inspect before any manual action. Slack delivery speed, bookmaker cutoff and available prices are not guaranteed. The default 10-second submission buffer is not an assurance of time to bet.

```bash
docker compose --env-file engine/.env -f engine/compose.yaml logs -f --tail=20 qualification-slack-worker
```

`submitted:N` means N webhook acknowledgements; `paused` means page pause; `disabled_or_unconfigured` means env/webhook setup is missing. Saved settings apply next scan and never resend an already claimed run.

## Stake-plan update

See [Production-only stake plans](alert-stake-plans.md): new qualified Slack alerts include a frozen conditional stake schedule based on saved capital and allowance. Missing inputs delay/suppress the alert. The earlier budget-only limitation is superseded. Delivery is blocked outside production and on local mirrors/snapshot databases.

## Unified settings page

Use **Alerts** instead of a separate Slack alerts page. See [Unified alert settings](unified-alert-settings.md). Select the previous Slack profile if it holds the capital/rules you want to keep, then save once for both delivery channels. Credentials and delivery history remain separate.
