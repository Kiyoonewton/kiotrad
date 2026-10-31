# Reviewed production history import

This is the next step after the production read-only plan and successful isolated backup restore. Deploy this code first. The command defaults to a **database read-only preflight**; `--apply` is a separate operator action. No estimated dates or research profits are imported.

## Stage the reviewed files

Keep the tested backup on the Mac. Copy only its `report.json` attestation, the original production plan's `report.json`, and the retained legacy package to a dedicated production host folder. For example, from the Mac repository:

```sh
server=root@169.58.227.92
ssh "$server" 'mkdir -p /root/kiyoo/kiotrad/recovery/reviewed-history'
scp -r recovery/legacy-history-batch-1 "$server:/root/kiyoo/kiotrad/recovery/reviewed-history/package"
scp recovery/production-history-plan-20261031T113918/report.json "$server:/root/kiyoo/kiotrad/recovery/reviewed-history/production-plan.json"
scp backups/production-pre-import/20261031T113127212690Z/report.json "$server:/root/kiyoo/kiotrad/recovery/reviewed-history/backup-report.json"
```

Use a fresh staging directory if files already exist there. The importer validates exact source bytes and reviewed identities again. Staging copies files; it does not import database evidence. The attestation pins the backup to the exact production plan; retain the actual independently verified archives for recovery.

## Run the preflight on production

```sh
cd /root/kiyoo/kiotrad
set -o pipefail
docker compose --env-file engine/.env -f engine/compose.yaml \
  run --rm --no-deps -T \
  -v "$PWD/recovery/reviewed-history:/reviewed:ro" api \
  php -d memory_limit=512M artisan provider:import-reviewed-history \
  /reviewed/package /reviewed/production-plan.json /reviewed/backup-report.json \
  | tee recovery/reviewed-history/preflight.jsonl
```

Review the final `passed: true, applied: false` summary. Initial expected additions in the existing reviewed plan are 20,464 fixtures and 29,040 prices. Every season's exact proposed fixture/price IDs must match, not just aggregate counts. Changed production evidence, code/settings, source hashes, conflicts or backup linkage stop the command. Produce and back up a fresh plan if review requirements change; never edit the old report to pass a check.

## Apply only after reviewing the preflight

Repeat the command with `--apply` after the backup argument and save output as `apply.jsonl`. This writes original raw envelopes before normalized captures, a resumable import ledger and a completed-package descriptor. It adds a supporting `(competition, season_id)` capture index. Retry exactly the same package, plan and backup after interruption. Do not delete its prepared ledger or change its proof files to force a retry.

Existing captures, season capture pointers, provider pointers, settings, paper balances and standings are preserved. Missing prices clone the existing verified fixture. New fixture schedules and scores stay unknown. Historical odds are never marked pre-match verified. The import does not certify champions, estimated dates or financial eligibility. It does not automatically resolve contradictory source prices; those require a new review.

The importer serializes other reviewed imports, not the live collector. It rechecks each season before writes; this is not a multi-collection transaction. If a later season changes, earlier completed seasons and raw evidence remain durable for review/resume. A completed capture with missing/changed materialization fails closed rather than silently rewriting historical records.

After completion, normal prod-to-local raw sync reconstructs the retained package from the permanent descriptor and exact original responses before replaying overlays into isolated staging. A fresh Mac does not need an old local database. Package reconstruction failure prevents activation; the current local dashboard stays active. One automatic full local index rebuild occurs when this parser version first changes, then incremental processing resumes.

Finally inspect the production worker's dashboard and workbench readiness and re-audit daily totals using the production capital/settings. The production benchmark remains distinct from the ₦50,000 estimated-history research comparison. Import completion alone is not dashboard readiness or a source-accuracy certification.
