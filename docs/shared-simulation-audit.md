# Historical timing and fixture audit

A settled fixture downloaded days later proves the result, but its download time
is not the match finish time. Current portfolio replay uses recorded confirmation
times; historical balances and clash counts remain provisional. Do not deploy
this benchmark as a verified event-time simulation.

The read-only audit separates that issue from missing fixture minima:

```bash
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml exec -T api \
  php artisan strategy:audit-shared --fixtures > /tmp/kiotrad-audit.json
```

Run after rebuilding API and dashboard-worker and seeing `dashboard: refreshed`.
The audit reads the cached dashboard and scans stored normalized captures. It
does not request provider data, rewrite permanent raw evidence, update baselines,
place simulated stakes, or change the balance. Scanning captures can take time.

- `confirmation_crosses_scheduled_attempts`: confirmation was recorded at or
  after another scheduled attempt. This is a timing-evidence warning, not proof
  of an actual match duration or actual overlap.
- `blocks_runs`: candidates excluded by that selected run in the current replay.
- `capture_span_hours`: collection-based span, explicitly not event duration.
- `baseline_rebuild_needed`: matching earlier captured odds exist, but the derived
  lookup is missing. Repair/rebuild derived baselines next.
- `dashboard_snapshot_needs_refresh`: the baseline exists now, but the dashboard
  still reports it missing.
- `no_matching_earlier_raw_price_found`: no matching price found in the normalized
  captures inspected. This does not prove that every permanent raw file has been
  decoded, nor that a provider archive cannot supply the missing evidence.
- `fixture_evidence_unavailable`: fixture identity or the original sizing cutoff
  is unavailable. Refresh the dashboard with the updated code before auditing.

The audit retains the exact venue and outcome and uses the sizing run's original
season-start cutoff. It never borrows later-season minima to fill history.

Next timing repair requires provider event-end evidence or a separately labelled,
explicit simulation timing assumption. Neither kickoff nor the next kickoff is
silently substituted for a finish time. Existing balances are not recalculated
from guessed times by this audit milestone.

## Offline raw-odds recovery

The local image includes `provider:recover-raw-odds {folder}`. It verifies stored
raw response checksums, joins exact league/match/team/round identities and checks
whether those recorded prices are already normalized. It makes no HTTP requests.
Production images exclude this command and its implementation; the scanner also
requires `LOCAL_DATA_MIRROR=true` and a timestamped `kiotrad_local_*` database.

Mount a retained raw-sync snapshot read-only. The folder is the snapshot directory
containing `provider-evidence/raw`, not a newly downloaded export:

```bash
cd "$HOME/Developer/kiotrad"
raw_snapshot="$(find "$PWD/backups/raw-sync" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"
test -n "$raw_snapshot" && test -d "$raw_snapshot/provider-evidence/raw" &&
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  run --rm --no-deps -T -v "$raw_snapshot:/raw-evidence:ro" api \
  php artisan provider:recover-raw-odds /raw-evidence/provider-evidence/raw \
  > /tmp/kiotrad-raw-odds-audit.json
```

Read the counts and per-season results. `recoverable_files` means raw prices exist
but an equivalent normalized snapshot was not found. `already_present` means the
captured prices are already represented. `empty_odds_files`, `invalid_files` and
`missing_fixture_files` remain explicit gaps. Invalid files cause a nonzero exit;
valid files can still be recovered when applying, so reruns are safe.

Repeat with `--apply` after the folder argument to import genuine missing prices.
The raw files remain read-only. New normalized captures retain original receipt
times; later fixture results are cleared rather than backdated. Repeated imports
recognize existing prices and avoid inserting equivalent snapshots. Apply mode
also invalidates affected season-derived caches, including already-present odds,
so interrupted imports can be repaired by rerunning without flushing all Redis.
Wait for the next dashboard refresh, then rerun `strategy:audit-shared --fixtures`.

This cannot recover odds that were never captured. Such gaps need the existing
production historical-odds recovery commands and a later local sync. It does not
change the unresolved event-finish/overlap timing policy or invent prices.
