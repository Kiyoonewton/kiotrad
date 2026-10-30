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
replay. Register a retained package for automatic raw sync replay:

```bash
python3 -u engine/local/sync.py --ssh root@169.58.227.92 \
  --history-package recovery/legacy-history-batch-1
```

The first successful staging import pins the package path and manifest hash in
ignored `recovery/history-overlays.json`. Future normal syncs automatically replay
registered packages into the staging database before validation, warming and
activation. This also applies to `--full-rebuild`. Register multiple packages with
repeated `--history-package` arguments. No package data is copied into snapshot
backup folders; existing source packages and `recovery/local-history-evidence`
remain outside snapshot retention. Keep both folders.

A missing or changed registered package stops sync before activation. The importer
checks every source hash, preserves originals, refuses conflicts, and resumes its
completed ledgers. Missing derived captures/checkpoints can be restored from their
pinned completed plans without replacing an existing verified checkpoint. An
unchanged completed import does not add captures or bump evidence revisions.

Registration remains enabled if later view preparation/activation fails, so a
normal retry keeps the same evidence. To relocate an unchanged package, pass its
new path with `--history-package`; its identical manifest identifies the existing
registration. Changed evidence must be packaged in a new directory. Registered
overlays require the normal raw sync path: `--activate` and `--legacy-normalized`
are rejected rather than silently activating a database without the overlay.
This remains local-only; it does not add production import or estimated timing.

## Verify retained history after sync

Normal registered-package sync now runs `provider:verify-legacy-history` after
imports and before warming or activation. It compares each materialized capture
with its pinned ledger plan, including fixture identities, results, timing,
quotes and provenance. Matching counts alone are insufficient. Missing captures,
changed evidence, incomplete ledgers or absent season checkpoints stop activation.
An existing verified checkpoint may point to a different provider capture.

The JSON-lines report is retained outside snapshot cleanup at
`recovery/history-verification/<staging-database>.jsonl`. Each ledger has a season,
source hashes, expected/actual evidence hashes, fixture/quote/untimed counts and
issues. `verified` means the normalized capture matches its pinned plan;
`raw_preserved_only` means the completed plan required no new normalized evidence.
The final `history_verification` summary must have `passed: true`. This is not
verification of unknown event timing, champions, archive availability or profit.
Source files are separately hash-checked by the importer before verification.

To inspect the active local snapshot without a new sync:

```bash
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml exec -T api \
  php artisan provider:verify-legacy-history
```

For the normal-sync retention check in issue #68, retain the before/after reports
and compare ledger IDs and expected hashes. Newly collected provider evidence may
change what a fresh import needs, so review differences by season rather than
requiring fixed aggregate counts. The real Mac dataset still requires this
operator check; automated fixture tests cannot certify that external snapshot.
