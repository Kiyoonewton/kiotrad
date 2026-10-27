# Historical timing audit

Run on the Mac with both the legacy raw directory and an existing KioTrad raw
snapshot. This is a read-only diagnostic; no database access, import or cache
invalidation occurs. Progress is printed to stderr every 1,000 files.

```bash
cd "$HOME/Developer/kiotrad"
raw_snapshot="$(find "$PWD/backups/raw-sync" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"
test -d "$raw_snapshot/provider-evidence/raw" &&
python3 engine/recovery/timing_audit.py \
  "$HOME/Developer/my_trade-multileague/raw" \
  "$raw_snapshot/provider-evidence/raw" \
  > /tmp/kiotrad-timing-audit.json
```

The report lists seasons independently by competition, verified matchday
coverage, day-one anchors, conflicting timestamps, observed spacing and
leave-one-interior-day-out interpolation errors. Odds coverage counts unique
match IDs only; it does not certify valid prices, settled results or complete
seasons. No response timestamp is substituted for kickoff.

A missing anchor or conflicting day blocks a claim of verified timing. Even
zero held-out error inside a season does not prove that older seasons maintained
that cadence or had no breaks. Numeric season-ID differences and discovery
lists are not verified consecutive links. This first audit deliberately does
not extrapolate across seasons, modify the capital graph or create estimated
fixture records. The resulting report supplies evidence for the next decision
about explicit interpolation policy and uncertainty.

## Source-aware report (version 2)

The two positional arguments are explicitly legacy first, KioTrad second.
Overlapping or nested input directories are rejected. Each season has separate
`legacy` and `kiotrad` coverage, `source_presence`, shared odds match IDs,
legacy priced IDs without a KioTrad price, and `kiotrad_timing`. Timing anchors
come only from the KioTrad input. A fixture in KioTrad does not imply odds exist.

Both complete and partial seasons remain in the report. Each source lists
priced IDs, odds-derived results, missing/partial days, empty 1X2 market event
responses and conflicting results. These are evidence descriptions, not proof
of a market interruption. Empty responses followed by priced responses remain
visible. Multiple price versions can be normal market changes. No historical
collection-failure cause is inferred from missing files. Match-ID coverage is
not a full season-integrity certificate, and this command does not access live
MongoDB or import any records.

The former flat `legacy_match_ids` field was incorrect: it combined sources.
Do not reuse version-1 overlap summaries. Generate a new version-2 report.
