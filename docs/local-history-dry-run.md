# Local historical package parser and database dry run

The local image now provides `provider:audit-legacy-history`. This command has
no apply option. It requires LOCAL_DATA_MIRROR and a timestamped local database
name, and is removed from production images together with its implementation.
It does not write MongoDB, Redis, provider archives or simulation balances.

After merging, build only the local API image, then mount the already verified
package read-only. A new raw download or package rebuild is unnecessary.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml build api &&
docker compose --env-file engine/local/.env.local \
  -f engine/local/compose.yaml run --rm --no-deps -T \
  -v "$PWD/recovery/legacy-history-batch-1:/legacy-history:ro" api \
  php artisan provider:audit-legacy-history /legacy-history \
  > /tmp/kiotrad-history-database-audit.jsonl
```

Output is one JSON line per season and a final summary. Database captures are
streamed once per season, with repeated identical match/price evidence
collapsed in memory. Match-ID lookups also search outside the season to catch
stored cross-season identity collisions. Counts describe source observations;
`unique_match_ids` is the deduplicated match count. A nonzero exit means a
rejected season or identity/result conflict; retain and inspect the report.

The parser preserves team IDs, names, genuine H/D/A outcomes and valid 1X2
prices, including partial evidence. Unknown scores, original retrieval times
and scheduled/actual kickoff times stay null. Provider timestamps and market
status -3 are not interpreted as kickoff or proof of blockage. Malformed
identity, mixed leagues, duplicate outcomes and multiple winners are rejected.

The comparison reports new/existing fixtures, missing/matching/different
prices, available schedules and identity/result conflicts. It never chooses a
replacement price or rewrites verified timing. All source versions of an
intra-season conflicting match are flagged, rather than selecting the last.
The package manifest and source hashes are checked before database reads;
source hashes are checked again when parsed. The mount must remain read-only.

This milestone does not persist new historical captures, establish source
season continuity, infer a champion tie-break or implement estimated timing.
Those policies and an explicit apply path follow review of the database audit.
Six unclassified source responses from the earlier package remain in the old
raw folder; they were not silently imported or labeled blocked.
