# Check the three added runs against original raw evidence

The ledger audit checks arithmetic. This next step for #70 independently reads
original archived responses to corroborate the champions, first-win qualification,
actual attempt results/prices and cited earlier sizing quotes for newly completed
runs. It uses Python, not the PHP candidate builder, and does not need Docker,
MongoDB, SSH, a password or another full raw-data scan.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/audit_run_sources.py \
  recovery/estimated-history-comparison.json \
  --package recovery/legacy-history-batch-1 \
  --destination "recovery/added-run-source-audit-$(date +%Y%m%dT%H%M%S)"
```

Progress identifies the selected raw seasons. The three newly completed runs in
the current comparison are Euro 3146586 Next Win, English 3146626 Next Win and
English 3146652 Next Season. The command derives the target list from the saved
comparison and checks it against both completed-run ledgers; it does not hardcode
those seasons or silently substitute another simulation.

Checks include:

- Package plan checksum, manifest identities, selected raw file SHA-256 hashes,
  safe paths, league/season/matchday routes and provider team/match identities.
- Full 240-fixture/30-day/16-team round-robin integrity and settled results for
  each champion dependency. Partial seasons cannot supply a champion; tied
  points do not select an arbitrary leader. Top-three points totals are exported.
- First win found from day 1 in the correct trigger season/team, against the saved
  trigger day and qualification setting. W/D/L sequence and fixtures are retained.
- Every executed attempt backs the correct team on the correct matchday. Its
  price and result must occur in the exact original raw file cited by its quote
  provenance, rather than merely somewhere in the archive.
- Every sizing quote used in the selected attempt window must be supported by
  original bytes from its cited earlier season and match the exact home/away
  venue. The source season must precede the run under the reviewed chronology.

A mismatch produces FAIL, a nonzero exit and issue rows. An unreadable, corrupted
or contradictory source stops the audit without producing a passing report.
Existing output folders are never overwritten. No source data is modified.

Open the printed output folder on the Mac:

| File | Evidence |
| --- | --- |
| runs.csv | Team, strategy, trigger season/team, first-win day, W/D/L prefix and pass/fail. |
| champions.csv | Expected champion, independently calculated unique leader, completeness and top-three points. |
| quotes.csv | Actual-attempt and sizing prices, fixture/season, raw hashes and claimed quote capture ID. Odds are scaled by 10,000. |
| issues.csv | Failed checks, if any. |
| report.json | Comparison and manifest checksums, selected verified raw hashes, all rows and limitations. |

`comparison_sha256` should match `source_sha256` in the previously passed ledger
audit report, proving both checks refer to the same comparison file. Preserve the
comparison and both audit folders together.

A PASS corroborates the original archive's content, not the accuracy of the
provider itself or historical pre-match market availability. It does not verify
MongoDB capture-ID associations, prove that a cited sizing quote was the global
minimum across all older seasons, or certify the assumed season adjacency and
kickoff dates. The older dates still have unknown uncertainty. Later compounding
changes and the rest of the recorded benchmark are outside this focused audit.
Production evidence transfer remains a separate task after these limits and the
real output have been reviewed.
