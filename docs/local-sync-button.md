# Sync production data from the local dashboard

The **Sync prod → local** button uses the existing incremental raw sync,
registered legacy overlays, validation, staging prewarm, activation and retention.
It does not pull Git changes or write to production. Keep strategy code aligned
on production and local; existing snapshot mismatch checks remain enforced.

## One-time setup on your Mac

Run from Terminal after merging/updating main:

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 engine/local/sync_agent.py --setup &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml \
  up -d --build --force-recreate --no-deps api web dashboard-worker
```

Setup asks for the production SSH alias/host and the `kiotrad_dev` **read-only**
MongoDB password once. It saves them to ignored, mode-0600
`engine/local/.env.sync`, which is excluded from Docker build contexts and is
not mounted into containers. You can instead supply `KIOTRAD_SYNC_SSH` and
`KIOTRAD_SYNC_PASSWORD` in the helper's host process environment. File values
are plain or JSON-quoted strings; the file is never executed as shell code.
Never commit it or paste credentials into chat/browser settings.

The helper runs as your macOS login user through
`~/Library/LaunchAgents/com.kiotrad.local-sync.plist`. The plist has no password.
Docker Desktop must be running and SSH must work without an interactive password
or key prompt. The child sync uses `BatchMode=yes`, so it fails rather than hangs
waiting for SSH input. Existing host SSH configuration/keys are used directly;
no SSH keys or Docker socket are given to web/API containers.

Refresh **http://localhost:5174**. The sync panel sits above the page content.
Click **Sync prod → local**; further clicks are disabled while it runs. The helper
continues to serve progress while the API/web containers are recreated. It starts
at login but never syncs automatically without a button click.

## Progress and ready state

The panel shows the current phase, elapsed seconds and a percentage only when
real done/total counters are available (for example history import and view
preparation). Download/rebuild phases without counters show a phase, not an
invented percentage or ETA. Child logs, credentials and remote command arguments
are never returned to the browser. Errors give a sanitized failure state; run
the existing terminal sync command for detailed diagnostics.

Success requires sync exit 0, a changed timestamped active database, local HTTP
readiness, an overview identifying that database and an available Simulator
scenario. Only then does the panel say **Dashboard ready to view**, and it
refreshes the displayed overview. A readiness timeout is reported as failure,
not success. The current snapshot/rollback behavior remains owned by sync.py.
Existing terminal sync and local-database leases still protect against competing
runs. A second helper request cannot start a second job.

## Availability and troubleshooting

The local Compose build alone sets `VITE_LOCAL_SYNC=true`. The control also
requires localhost/127.0.0.1 port 5174. Production builds default to disabled.
The host HTTP server binds **127.0.0.1:5176** and accepts only the exact local
Host/Origin combination, a random per-process token and a parameterless POST.
It exposes no shell/SSH/restore command endpoint. Production page origins cannot
start it even if their JavaScript is modified to show a button. The helper itself
refuses to run outside macOS; sync.py separately verifies the local Docker
endpoint, project labels and destination volume.

An offline helper leaves the button disabled and shows the setup command.
Browser local-network permission may need to be allowed for the local dashboard.
Do not rerun setup, close a manually running helper or log out during a sync.
Setup refuses to replace an active helper job. If the repository moves, rerun
setup from its new location. Helper startup logs (no child raw logs) are at
`recovery/local-sync-agent/helper.log`.

To stop the login helper when idle:

```bash
launchctl bootout "gui/$(id -u)/com.kiotrad.local-sync"
```

Normal terminal sync also reads the private env password before prompting:

```bash
python3 -u engine/local/sync.py --ssh root@169.58.227.92
```

This implements the env credential and local progress/readiness path discussed
in #67. It does not add a production-side sync button or change source evidence.
