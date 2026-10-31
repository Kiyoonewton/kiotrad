# Inspect rejected raw documents

After the completed 61,160-file sync, the rebuild reported 57 `invalid_documents`. The old counter did not retain their identities or exception reasons. Its exact historical rejection list cannot be recovered from that number.

Run this on the Mac after merging:

```sh
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/local/audit_raw_documents.py
```

The launcher selects the active timestamped local database and its retained raw snapshot. It builds the local API image for this one-off audit, mounts original files read-only and writes a new report folder under `recovery/raw-document-audit/`. It does not sync, rebuild, activate a snapshot, restart the dashboard, request production credentials or access production. The rebuild parser files and version are unchanged, so this diagnostic alone does not force another full sync.

Progress appears every 500 indexed files. `report.jsonl` contains source-relative filenames, compressed hashes, phases, season IDs, fixed rejection reasons, skipped/missing context and a final completion summary. `rejections.csv` is a table of rejections and documents that could not be validated. Raw response bodies, URLs and credentials are excluded. Existing report files are never overwritten. Preserve partial reports if the command fails; only a complete summary certifies that this audit finished scanning.

This is **current revalidation**, using the current normalizers and stored `raw_rebuild` fixture context. It is not replay of the historical processing event. Its rejection count may differ from 57. A missing fixture join context is distinguished from a rejected document, non-200 responses and missing response data. Empty normalized odds may validate without producing usable prices, as in the rebuild; validated does not mean complete price coverage or financial eligibility. It does not certify champions, kickoff times, daily arithmetic or profits.

Use the reported phase/season/reason to identify affected evidence, then check whether another stored response supplies valid coverage. Do not delete or replace rejected originals merely to make the count zero. Imported partial seasons and historical market closures remain evidence for separate analysis.

## Recheck only the earlier rejections

After the covering-fixture lookup fix, use the completed original report:

```sh
python3 -u engine/local/audit_raw_documents.py \
  --rejections-from recovery/raw-document-audit/20261031T190617134347Z/report.jsonl
```

This selects only the rejected files, verifies their original compressed hashes against the same active database's index, and records the covering fixture capture and quote coverage in `join-context.csv`. It validates all events in each response, not only the first previously failing match. Quote totals count response occurrences, not unique matches. Matching prices already present need no duplicate import; different existing prices are preserved for review. Legacy raw acceptance restrictions remain in place; a passing join is not authorization to add prices or backdate their availability. No database writes occur.

The lookup now prefers the latest capture at or before the odds observation that covers every requested match ID. If only a later covering capture exists, result fields are cleared and its prices cannot become verified pre-match from that later context. Team/league/round validation remains strict. It does not merge incomplete captures or manufacture missing fixtures, matchdays, standings or champions.

This subset audit does not rebuild or activate anything. The parser correction changes the version of derived raw reconstruction; the next normal sync will perform one automatic full staging rebuild to apply corrected joins consistently. Downloaded raw files can still be reused. Do not edit the processing-version marker to avoid that rebuild.
