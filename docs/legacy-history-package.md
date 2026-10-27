# Legacy history package and offline dry run

This step stages evidence, not an import. It does not connect to MongoDB, write
Redis, alter provider archives, stop collectors or change simulation balances.
Original raw bytes are copied with SHA-256 checksums; unknown original receipt
times remain null. All recognized legacy season odds responses are included,
even empty 1X2 markets, partial seasons and already overlapping history.
Unsupported, oversized, unknown/mixed-league and malformed inputs are counted.
Responses without identifiable league events cannot be attributed and are not
staged. Source data is never deleted or modified.

Run after pulling main on the Mac:

```bash
cd "$HOME/Developer/kiotrad"
raw_snapshot="$(find "$PWD/backups/raw-sync" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"
test -d "$raw_snapshot/provider-evidence/raw" &&
python3 engine/recovery/package_legacy_history.py \
  "$HOME/Developer/my_trade-multileague/raw" \
  "$raw_snapshot/provider-evidence/raw" \
  "$PWD/recovery/legacy-history-batch-1"
python3 engine/recovery/verify_history_package.py \
  "$PWD/recovery/legacy-history-batch-1"
```

Use a new destination; existing directories are never overwritten. A failed
run may leave a partial folder, but the manifest completion marker is written
last and verification must pass before consuming a package. Keep packages out
of Git. Do not run the existing legacy odds importer against the whole new
history package: it requires eight existing round fixtures and cannot create
historical fixtures or preserve partial history by itself.

`plan.json` is a comparison against the supplied snapshot, not current prod.
It reports source presence, coverage, partial days, empty market evidence,
result conflicts, missing prices, matching prices and different recorded
prices. Multiple prices may represent ordinary movement; no price is selected
or overwritten by this command. Fixture results still need reconciliation
with odds-derived outcomes before any database apply. Coverage does not prove
season continuity or full fixture integrity.

The next implementation is an explicit local history parser/import path,
followed by production dry-run comparison. Missing kickoff dates and scores
remain unknown; estimated timing requires separate policy and provenance.
Partial seasons remain useful interruption evidence without implying a
confirmed market blockage. No financial replay is certified by this package.
