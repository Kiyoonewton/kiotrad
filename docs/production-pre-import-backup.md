# Back up production evidence and verify an isolated restore

This is the next preparation step for #71 after reviewing the read-only plan.
It reads production, writes a private backup on the Mac, and restores that backup
only into a fresh disposable database in the isolated local Docker project. It
has no production restore or import option and never changes the active local
environment, dashboard database, production capital or strategy settings.

## Run on the Mac

Use the existing saved read-only sync credentials. Keep the local MongoDB volume
available; the tool checks the local Unix Docker context, project labels and
volume before restoring. SSH must work with key authentication in batch mode.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/backup_production_history.py \
  --plan recovery/production-history-plan-20261031T113918/report.json
```

Optional `--ssh root@169.58.227.92` overrides the saved host. The backup goes into
a new private `backups/production-pre-import/<UTC timestamp>` folder, outside
normal local snapshot retention. Do not commit it or delete it during sync
cleanup. Its original files are private data; keep a separate durable copy before
an eventual production write. This command does not make that off-machine copy.

## What is preserved and checked

1. The reviewed production plan and current strategy definitions/settings/code
   hashes. A mismatch with the plan or local replay stops before database backup.
2. Every ordinary application collection in `kiotrad`, using the read-only account
   and compressed native BSON archives. Capture documents are exported last to
   support earlier checkpoint references. System collections/users, MongoDB
   configuration, env secrets and deployment binaries are outside this backup.
   Unsupported views/time-series collections stop the workflow for review.
3. Collection/index metadata, archive hashes and sizes. Native restores use a
   fresh `kiotrad_local_backup_check_...` namespace, never the active snapshot.
   Document counts must match the native restore summaries and all restored
   checkpoint capture references must resolve. Dates and integer BSON types are
   preserved by native tools and exercised in the real MongoDB CI smoke test.
4. Permanent original raw responses with a frozen manifest, exact file hashes
   and decoded envelope/body hashes. Verified existing raw files are copied as
   independent files, never hard-linked. Only missing/changed bytes download.
5. Exact final last-good dashboard/Simulator files, checksums and expiry metadata.
   These are rebuildable caches; Redis cache/lock state is deliberately not part
   of a durable data restore.

Progress prints by collection and raw-file count. No precise ETA is promised.
The active local `.env.local` bytes must remain unchanged. The disposable restore
namespace is removed only after successful verification; use `--keep-restore-check`
to retain it. Failed checks retain the files/test database for investigation.
Cleanup can address only that newly created backup-check namespace and again
checks the local Docker context/project/volume. No source collection is dropped.

## Completion and consistency limits

Only a final `report.json` with `complete: true` and
`database_restore_verified: true` establishes successful completion. An interrupted
folder, `archives.json`, or `restore-check.json` alone is not a completed backup.
The report lists archive/raw/view hashes and disposable database cleanup status.
If cleanup is pending, the backup can still be valid; inspect the exact reported
local namespace. Native backup/restore stderr is not printed with credentials.

Production collection keeps running. This is a rolling, non-transactional backup,
not an oplog/PIT snapshot of all collections at one instant. Source estimated
counts are informational; restore counts come from the archives themselves.
Secondary/TTL indexes remain preserved in archive/index metadata but are not
activated during the data check, so cached documents cannot expire mid-check.
Index recreation and whole-application point-in-time consistency still need to
be addressed in the eventual rollback procedure. Do not treat this data check as
a tested full production rollback or approval to import.

Unlike ordinary sync's 60-second raw safety lag, the post-dump backup manifest
includes newly finished raw files. An incomplete/racing file must fail checksum
or envelope validation; no completion marker is published. Files created after
the manifest freeze are outside this backup. Strategy/settings changing during
backup also prevents completion. Last-good view files may expire after backup
and require rebuilding for the restored database/settings.

After inspecting the real backup result and retaining a durable copy, continue
with the separate raw-first, append-only production import implementation and a
fresh production comparison. Estimated kickoff dates stay research-only. No
production import, restore, deployment or settings change occurs in this step.
