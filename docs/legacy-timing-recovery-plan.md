# Review older-season timing recovery

Issue #69 needs a timing decision per season, rather than rejecting an entire
league/list because of one interruption. This tool reuses the existing reports:
no raw rescan, SSH, password, MongoDB or Redis access is needed.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/timing_recovery_plan.py \
  --timing-report /tmp/kiotrad-timing-audit.json \
  --discovery-report recovery/discovery-evidence-batch-1/report.json \
  --destination "recovery/timing-plan-$(date +%Y%m%dT%H%M%S)"
```

Use the original timing report whose hash is pinned by the discovery report.
If it was replaced, regenerate discovery against the new timing report using
`docs/legacy-discovery-evidence.md`; do not edit the pinned hash to bypass the
check. The tool refuses incomplete discovery input and existing output folders.

Open `seasons.csv` in the new output folder to inspect each season, its legacy
coverage/counts, recorded day-one kickoff in UTC/WAT, review-only hypothetical
time where supported, evidence and remaining requirements. `segments.csv` shows
anchor pairs, listed steps, actual versus expected gaps, and unexplained seconds.
Repeated discovery files share one segment row with all their source hashes; they
are not counted as separate interruptions. Different listed-step hypotheses stay
separate for review.
`report.json` preserves nested provenance and hashes of both input reports.
Partial seasons remain in the same table with their coverage marked.

| Status | Meaning / decision |
| --- | --- |
| recorded_day1 | Source audit has a nonconflicting day-one scheduled kickoff. |
| bracketed_cadence_hypothesis | Two verified anchors agree with listed steps × 6,720 seconds. An interior time can be proposed for review; adjacency is still unverified. |
| unanchored_extrapolation_required | Season lies before the first or after the last anchor. No time proposed; find an independent anchor or verify continuity and closure history. |
| anchored_break_unresolved | Season lies inside a timing break. Do not distribute unexplained delay evenly across it. |
| conflicting_cadence_hypotheses | Different candidate lists propose different times. No averaging or arbitrary choice. |
| conflicting_recorded_timing | Contradictory recorded evidence must be resolved first. |
| missing_day1_anchor | No usable bracket or one-sided discovery evidence was established. |

Each pair of verified anchors is evaluated independently. A later interruption
does not discard earlier bracketed agreement. The script recomputes direction
and segments from the pinned timing report and season identities, ignoring stale
cached calculations. Mixed leagues, duplicate IDs, nonmonotonic anchors and
rejected source identities cannot produce hypotheses.

The normal cadence is a hypothesis: 220 seconds between matchdays and 6,720
seconds between season starts. A positive multiple of 6,720 unexplained seconds
is marked `extra_whole_cadence_slots`: it could reflect omitted seasons or an
interruption, so it does not prove either. Other longer/shorter gaps are also
reported without inventing a cause. Known company-wide closures can occur at
different matchdays across leagues. Collector errors and empty odds responses
alone do not date those closures.

All hypothetical fields are explicitly separate from recorded kickoff fields.
This report changes no stored timestamps, provider links, odds, results,
champions, qualified runs or balances. Financial eligibility remains false for
this planning output: even recorded scheduled kickoff does not establish exact
settlement, champion integrity or historical market availability. Unknown
uncertainty is not presented as a measured confidence interval.

Next: review unresolved seasons/anchor segments from the real Mac reports.
Only then choose which continuity evidence supports a separately versioned
estimated-timing research path. Preserve the incomplete-season champion guard.

## Reconcile sparse discovery lists

Version 1.1 adds diagnostic reconciliation against every nonconflicting recorded
day-one anchor in the same league. Timestamp order, not season-ID arithmetic,
identifies known seasons omitted from a discovery list. Original listed steps,
raw gap and error remain available beside `omitted_recorded_seasons`,
`reconciled_steps`, `remaining_gap_seconds` and `reconciliation_status`.

For example, a list jumping from 3147261 to 3147317 can be reconciled when the
supplied recorded anchors include 3147289 between their kickoff times. The
13,440-second interval then covers two normal 6,720-second steps. The script
does not invent an omitted season merely because a gap is a multiple of cadence.

A list jumping from 3147661 to 3147738 can similarly show omitted 3147710 while
retaining the 10,860-second unexplained difference. Both this combined interval
and a direct 3147661→3147710 interval refer to the same unusual recorded boundary.
`recorded_boundaries.csv` and the JSON `recorded_boundaries` array deduplicate
these boundaries across all candidates and retain all supporting source hashes.
The boundary column `normal_cadence_difference_seconds` measures departure from
normal cadence; it is not a measured market-closure duration.

`remaining_gap_seconds = 0` only explains the aggregate interval under the cadence
hypothesis. Always inspect recorded boundaries too: normal aggregate timing can
hide a nonstandard subinterval, and listed untimed seasons remain unresolved.
Equal recorded starts produce `ambiguous_recorded_order`; the tool does not sort
ties by numeric season ID to manufacture chronology. Anchors from another league
never fill a gap. Recorded chronological neighbors are not certified consecutive
provider seasons, because other unrecorded seasons may still exist.

To reprocess the already completed Mac reports, without any raw scan:

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/timing_recovery_plan.py \
  --timing-report recovery/timing-review-20261030T065120/timing-audit.json \
  --discovery-report recovery/timing-review-20261030T065120/resumed-20261030T070300/discovery/report.json \
  --destination "recovery/timing-reconciled-$(date +%Y%m%dT%H%M%S)"
```

This reconciliation does not alter the older-season hypothesis logic, fill the
93 unanchored older seasons, infer champions, or enable financial replay. Review
the new summary and boundary table before deciding the separate extrapolation
policy. Existing reports remain intact.
