# Automatic view preparation

Default raw sync now reconstructs and validates the staging data, prepares the
overview and reusable Simulator evidence with its own database/capital settings,
and only then switches the active local environment. A failed preparation stops
activation; the existing local dashboard remains active. Watch the sync output
for dashboard/workbench phase messages. No separate warming command is needed.
Cold reconstruction/preparation still takes time; this moves that work before
the switch rather than claiming an instant first build. Full reconstruction
remains necessary when there is no completed processing index. Subsequent raw
syncs retain the existing incremental-index behavior.

The background worker always attempts both overview and Simulator refresh,
even when one fails. One-shot mode succeeds only when both are ready. Production
continues to poll for evidence changes every 30 seconds; ordinary API startup
now includes the worker dependency. Explicit --no-deps commands still skip
that dependency, so deploy API/web/dashboard-worker together when updating code.
Collectors continue preserving raw evidence independently.

Baseline refresh loads processing revisions in one query. Unchanged seasons
skip capture reads, and unchanged global minima skip historical scans/writes.
When evidence changes, only affected seasons are folded, and global minima are
reduced inside MongoDB instead of transferring every historical row into PHP.
This does not make all portfolio replay incremental; the versioned portfolio
still recalculates when its input revision changes.

Overview and prepared evidence also have a last-good backup on a dedicated
local/prod Docker volume. These are disposable derived caches, not raw evidence
or trading records. Backups expire after seven days and old files are pruned
when publishing. Keys include the database and relevant policy/settings, so a
previous database or capital setting is never silently displayed as current.
Redis misses/outages can use the matching backup without querying MongoDB in
HTTP requests. A completely cold cache/volume still needs background preparation.
A failed rebuild never overwrites a last-good backup. Both disk and Redis
failing together can still make views unavailable.

The Simulator keeps the last matching-settings result visible during transient
refresh failures, displays actual preparation phases/counts when supplied, and
never reuses a result for changed settings. Its evidence-through timestamp stays
visible. Default polling retries failed preparation automatically; stored source
evidence is unaffected. Logs contain failures rather than silently suppressing
the dashboard exception.

## Deploy

Local code update (no new raw sync required merely to install the fix):

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml up -d --build --force-recreate api web dashboard-worker
```

Production, from /root/kiyoo/kiotrad after updating main, with MongoDB and Redis already running:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  up -d --build --force-recreate --no-deps api web dashboard-worker
```

Do not delete Redis, MongoDB or evidence volumes. The new dashboard_views volume
stores expiring derived backups only; production and local use separate volumes.
Normal refresh requires the dashboard worker to be running. This change does
not auto-deploy code, reconnect a stopped Docker daemon, repair unverified
historical timing, or restore local-only history overlays after a full sync.

## Calendar coverage and chart inspection

The overview and Simulator show Lagos calendar dates containing stored settled
fixtures, earliest/latest scheduled kickoff, elapsed 24-hour days, missing dates
within that span, and fixtures without timing. A date with records does not imply
complete coverage. Untimed legacy history is retained but excluded from the time
span. Coverage runs in the background, deduplicates provider match IDs per season,
and caches each season against its capture and odds-evidence revision.

Overview polling retains the last successful view when a refresh returns warming
or unavailable, with an Updating notice. A genuinely cold first visit still shows
Preparing until a view exists. Line/bar chart inspection shows the selected balance
and WAT time inside the chart; candles show open/high/low/close. The keyboard event
scrubber also updates this readout.
