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
