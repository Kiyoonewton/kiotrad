# Unified Alerts page

One **Alerts** navigation entry replaces separate Email alerts / Slack alerts pages. One form controls capital, allowance, league, minimum lead time, per-strategy qualification/attempt limits and strategy enable flags. Email and Slack have independent delivery switches, alongside their server configuration status. Both channels continue using separate credentials, delivery records and worker services.

## Existing settings and migration

Nothing is overwritten merely by opening the page or deploying code. Until the first shared save, workers read their existing channel settings. The page compares both profiles. If parameters differ (for example email ₦50,000 and Slack ₦100,000), choose **Use Email** or **Use Slack**, review/edit the values, then save. A migration source is required by the API as well as the UI. If parameters already agree, save directly. Independent channel pause states are retained.

Saving writes one atomic `qualification_alert_settings/current` record. Both workers read it next scan, deriving effective enabled state from global pause AND their channel switch. This avoids a partial two-request save leaving email and Slack with different capital. Old channel records remain preserved. Legacy API clients read effective shared parameters; their parameter saves update the shared record and only their own channel switch, not the other channel or global pause.

The simulator and paper bankroll are unchanged. Live conditional stake plans, production-only guard, deadline/source checks and deduplication are unchanged. Already claimed alerts are not resent by a settings change.

## Settings key

`QUALIFICATION_ALERT_SETTINGS_TOKEN` is the optional shared key (at least 32 characters). If absent/empty, the unified API uses the existing email settings key, or the Slack settings key when email's key is empty. An explicit shared key also takes precedence for legacy settings endpoints. Keys remain in browser component memory and clear when locked or navigating away. Server recipient/webhook/SMTP credentials are never returned to the page.

New endpoint: GET/PUT `/api/v1/notifications/alert-settings`. Every read/write requires bearer-key authentication. A local page can inspect/edit its own settings but local/test delivery remains blocked even with credentials or saved enabled flags.

## Deploy

No new key is required if the email key already works. Update production code and rebuild/recreate API, web, dashboard worker and whichever qualification workers are active. Restarting API alone will not refresh long-running worker code. Keep `APP_ENV=production` only on the production server; mirror databases remain blocked. Then open **Alerts**, unlock, select an existing profile if requested, and save once for both channels.
