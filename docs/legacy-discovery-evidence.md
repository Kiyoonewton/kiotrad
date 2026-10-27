# Preserve diagnostics and audit discovery candidates

The extra legacy folder contains duplicate match files, empty responses,
placeholder requests and collector metadata. Preserve diagnostic evidence
without importing duplicate match records or trusting scraper league labels.
This local Python tool does not connect to any database or change simulations.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/audit_discovery_evidence.py \
  "$HOME/Developer/my_trade-multileague/raw" \
  "$HOME/Documents/work-space/my_trade/raw" \
  --timing-report /tmp/kiotrad-timing-audit.json \
  --imported-manifest recovery/legacy-history-batch-1/manifest.json \
  --destination recovery/discovery-evidence-batch-1
```

Requires the version-2 source-aware timing report already generated. It reads
both raw folders once, prints progress every 1,000 files and preserves only
files whose hashes are absent from the imported package. Exact bytes are
stored once per hash, with all source aliases in manifest.json. New output
folders only; source files and existing packages are not changed. These files
are under ignored recovery/ and must not be committed.

Inspect recovery/discovery-evidence-batch-1/report.json. It contains empty odds
responses, invalid routes, reported collector failures, discovery candidates
and per-source read failures. The final manifest records source/report hashes
and complete=false if any input failed to read or parse. A nonzero exit means
review read_failures; no unreadable input is treated as evidence of a blockage.

Only discovery_ids and list-valued order fields are candidate sequences.
season_ids is a collection/resume work list, never assumed chronological.
League identity comes from recognized tournament IDs in match responses and
KioTrad-present seasons in the source-aware report. Declared league labels,
filenames and response timestamps are not used as kickoff/league proof.
Mixed, conflicting, unknown and duplicate identities are flagged without
silently filtering lists to manufacture adjacency.

Direction is measured from verified day-one anchors, never the scraper's order
label. Each anchored segment compares its actual time gap to listed steps times
6,720 seconds (the current cadence hypothesis). A mismatch can mean missing
seasons, an interruption or a wrong candidate order; the tool does not decide
which cause occurred. Nonmonotonic anchors are rejected. Agreement only within
the anchored section does not prove older unanchored cadence or completeness.
Every candidate remains eligible_for_estimation=false at this evidence stage.
No links are promoted into provider-confirmed continuity.

Collector-reported network/incomplete statuses, archive UI failures, timeouts
and cancellations remain collection diagnostics. Repeated empty odds responses
establish what those requests returned, not what betting access was when a
historical event took place. Full original responses remain available by hash.
