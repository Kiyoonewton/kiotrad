# Read-only production legacy-history plan

Issue #71 starts with a review plan, not a deployment. This command compares the
retained original package against production using the existing `kiotrad_dev`
read-only MongoDB account. It checks the exact strategy-code hashes and replay
version before reporting import candidates. It has no `--apply` option and does
not enable the local importer in the production image.

## Run from the Mac

The existing local sync setup must already have saved `KIOTRAD_SYNC_SSH` and
`KIOTRAD_SYNC_PASSWORD` in private ignored `engine/local/.env.sync`, or supplied
them through the process environment. The password is read from that file without
another prompt. It is sent only in the SSH stdin script, never argv, browser,
reports or logs. SSH key authentication must work in batch mode.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/plan_production_history.py \
  --package recovery/legacy-history-batch-1 \
  --comparison recovery/estimated-history-comparison.json \
  --ledger-audit recovery/history-ledger-audit-20261031T101957/report.json \
  --source-audit recovery/added-run-source-audit-20261031T110418/report.json \
  --destination "recovery/production-history-plan-$(date +%Y%m%dT%H%M%S)"
```

An optional `--ssh root@169.58.227.92` overrides the saved host. Other command
arguments are not accepted as SSH options. The tool runs only on macOS. It never
starts, rebuilds or restarts production containers; it reads from the existing
`kiotrad-api-1` and `kiotrad-mongodb-1` containers. If they are unavailable, the
audit stops. No package or original raw files are uploaded to production.

The two passed audit reports must refer to this exact comparison, and the source
audit must refer to this exact package manifest. The command verifies every
package season/file before the production comparison, including partial seasons.
That full-package validation is broader than the earlier three-run source check.
Progress identifies local source seasons, then production fixture/quote group counts and per-season plan counts. It does
not claim a duration estimate.

## What is read

A settings-only PHP snapshot exports strategy definitions, replay version, code
hashes and configured simulation capital. A separate mongosh script connects as
`kiotrad_dev` and rejects any authenticated role other than `read` on `kiotrad`.
Only `connectionStatus` and two `provider_captures.aggregate` reads are used
against MongoDB. They match package fixture/quote IDs across all seasons so
identity collisions remain visible. Grouping removes repeated capture versions
inside MongoDB and streams distinct evidence to the Mac. `allowDiskUse` permits
MongoDB to spill aggregation working data to temporary disk rather than grow an
unbounded in-memory map; no collections or indexes are written. Invalid quote
counts describe distinct invalid variants, not repeated raw capture records.

The script fixes an observation cutoff and returns a header, distinct fixture/quote
groups with phase-completion markers, followed by a final completion marker. The
Mac then assesses every requested season exactly once. Missing, duplicated or
interrupted responses never publish a final `report.json`. Strategy/settings are read again
at the end; a change stops publication. This is not a transactionally consistent
MongoDB snapshot: late backfills require another audit before a later import.

## Review the output

- `seasons.csv`: append-evidence, raw-preservation-only or blocked disposition;
  full/partial result integrity; fixture/price/timing action counts.
- `fixtures.csv`: fixture, source/production results, quoted price variants,
  recorded schedule variants, proposed actions and concrete conflict fields.
- `production-inventory.jsonl`: distinct production evidence with cutoff and
  completion marker; no password or URI.
- `production-strategy.json`: non-secret definitions/settings snapshot.
- `report.json`: final completion artifact with all rows, pinned hashes, code and
  capital differences, required next steps and explicit no-write/timing policy.

All append actions are candidates for review. Source price ambiguity preserves
raw evidence without choosing a price. Existing verified fixtures, prices and
scheduled times are preserved. Different recorded prices are not overwritten;
identity/result or contradictory production-time evidence blocks the entire
season. Existing unconfirmed results are identified for review rather than
silently replaced. Partial seasons remain diagnostic evidence and acquire no
financial eligibility. Newly added fixtures would remain untimed; the 93 older
estimated dates and their replay remain separate research evidence.

A different production capital does not justify silently changing its settings.
Review that difference separately from original evidence transfer. Strategy
mismatches remain visible; nothing bypasses strict snapshot verification.

## Before any later production write

Review this real output first. A subsequent implementation must provide a
raw-first append-only import, durable idempotency ledger, conflict checks,
permanent original-file preservation and retry safety. Do not run the local
importer against production or change its guards.

Before an explicitly authorized import/deployment, take and verify backups of
production MongoDB, permanent provider evidence, strategy/settings and last-good
derived views. Test restoration into an isolated database. Pin the reviewed
package/code/plan, re-audit current production, and define rollback using that
backup or isolated staging. Never delete production volumes or collections as
an audit/cleanup shortcut. After a future import, verify collection processing,
overview and reusable Simulator readiness, capital/settings, daily totals and
retention of the last usable dashboard. This planner performs none of those
writes, backups, restarts or deployments itself.
