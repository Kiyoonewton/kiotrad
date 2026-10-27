# Apply the legacy history package locally

`provider:import-legacy-history` defaults to the read-only audit. With `--apply`
it requires a timestamped local mirror database and a writable archive root.
The command and importer implementation are excluded from production images.
This milestone does not support production application or estimate timing.

After updating main, use the existing package; no sync or new package is needed:

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
mkdir -p recovery/local-history-evidence &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml build api &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml run --rm --no-deps -T \
  -v "$PWD/recovery/legacy-history-batch-1:/legacy-history:ro" \
  -v "$PWD/recovery/local-history-evidence:/history-evidence" api \
  php artisan provider:import-legacy-history /legacy-history \
  --apply --archive-root=/history-evidence
```

The writable host mount is required: it retains compressed envelopes containing
exact original source bytes, source hashes, original filenames and explicitly
unknown receipt/status metadata. Do not substitute an ephemeral container
folder. Keep both the package and archive until production archival is added
and verified. They live under ignored recovery/, outside snapshot retention.
Do not commit the evidence or upload it as part of a code PR.

The entire package is hash-checked and parsed first. Cross-season source match
ID collisions abort preflight. For each season the importer archives originals,
compares current database evidence again, refuses identity/result conflicts,
and writes an insert-only normalized capture plus an import ledger. The ledger
pins each prepared plan so retries after a capture insert reuse the same
capture ID. Completed season retries do not change captures or checkpoints.
The lease prevents concurrent imports to the same local database and releases
on normal completion and PHP fatal shutdown; SIGKILL still relies on expiry.

Partial fixtures remain partial, unknown results are not losses, and absent
scores/kickoffs stay null. Empty markets are not classified as blockages. Only
missing prices are eligible. Matching/different existing prices are preserved;
multiple differing legacy prices for one missing match are archived but left
unresolved rather than selecting one. Existing verified fixture objects are
cloned when attaching a missing price, so replay and baseline joins retain
their real schedule, result and score. Existing checkpoint capture pointers,
collector states, current provider pointers, strategy definitions and paper
journals are not changed. An evidence revision invalidates derived caches.

Import-time observed_at/captured_at fields are processing timestamps with
explicit unknown-original-retrieval provenance. They do not establish historical
market availability, kickoff or settlement. Raw archives preserve full provider
metadata even when the normalized view has no reliable source timestamp.

Progress is one JSON line per season and a final local_apply summary. A conflict
preserves raw evidence but refuses that season's normalized write. A storage or
database error stops the run; rerun the identical command to resume completed
work. Check the completion summary, not the transient health of a CLI container.

New historical seasons will be stored, but may still show missing linked history
or timing until separate continuity and interpolation policies are implemented.
Their raw import does not establish a champion tie-break or certify a financial
replay. A full local resync/rebuild can discard derived imports; retain the host
archive/package and rerun this command against the new database if necessary.
Automatic local-overlay replay and production deployment are not part of this
milestone. Incremental seeds may retain imports, but should be verified after sync.
