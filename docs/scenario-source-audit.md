# Audit exported Simulator runs against raw sources

This read-only audit reconstructs champions from complete 240-result seasons, finds
first wins from day 1, checks qualification, and corroborates funded attempt IDs,
teams, days, prices and results against checksum-verified retained provider files.
It does not access MongoDB, change settings, or alter the 201-second settlement model.

The dependency CSV explicitly lists the previous and older season IDs to review.
These IDs are review inputs, not independent proof that no season was omitted.
`engine/recovery/reviews/oct18-19-dependencies.csv` selects the ten runs discussed
for 18–19 October 2026. It is not an automatically generated season schedule.

Save the downloaded `kiotrad-daily-reconciliation.json` in Downloads, then run:

```bash
cd "$HOME/Developer/kiotrad"
python3 -u engine/recovery/audit_scenario_sources.py \
  "$HOME/Downloads/kiotrad-daily-reconciliation.json" \
  --dependencies engine/recovery/reviews/oct18-19-dependencies.csv \
  --package recovery/legacy-history-batch-1 \
  --destination "recovery/scenario-source-audit-$(date +%Y%m%dT%H%M%S)"
```

Use the actual downloaded filename if the browser added a suffix. No export or
raw provider data belongs in Git. A missing selected run stops the audit rather
than substituting a different scenario.

Review `runs.csv` for qualification and its fixture sequence, `champions.csv` for
complete-season checks and top three, and `quotes.csv` for each funded attempt
and supporting raw hashes. `issues.csv` lists failures. `report.json` pins the
export, dependency CSV, package manifest and all verified source hashes.

A cited price hash must match its original bytes; another file cannot silently
replace it. When an attempt has no raw hash, matching archived evidence may
corroborate its price/result, but not the database capture-to-file association.
A tied points lead remains unverified because these odds files lack the score
information needed to independently establish tie-breakers.

PASS covers selected source evidence only. Kickoff timing, season adjacency,
pre-match availability, sizing minima and arithmetic remain separate checks.
Estimated historical dates are not promoted by this audit. A failure does not
modify the simulator or repair any source data.
