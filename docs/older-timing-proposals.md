# Review older dates before applying them

Issue #69: propose older dates from existing pinned reports without another raw
scan, database connection, or password. The report retains every season,
including partial seasons, and separates recorded dates from estimates.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/older_timing_proposals.py \
  --timing-report recovery/timing-review-20261030T065120/timing-audit.json \
  --discovery-report recovery/timing-review-20261030T065120/resumed-20261030T070300/discovery/report.json \
  --destination "recovery/older-timing-review-$(date +%Y%m%dT%H%M%S)"
```

Open `seasons.csv` in the printed output folder on your Mac. Filter `status` to
`estimated_unbounded` to see the proposed older dates in UTC and WAT. Inspect
`proposals` for the source-file hash, anchor season, recorded anchor kickoff,
listed steps and calibration seasons. `report.json` retains the same evidence
plus the input report hashes and policy. Existing folders are never overwritten;
keep these reports outside `/tmp`.

## Calculation and limits

For each accepted, single-league discovery sequence, anchor its older prefix at
the earliest recorded season in that league. Subtract 6,720 seconds for each
listed season step. Season-ID differences never represent elapsed time. The
assumption is that each prefix entry is consecutive and no unobserved closure or
schedule change happened there. Those assumptions are not verified by this tool.

The first two listed recorded anchors must have a calibration interval made up
entirely of recorded seasons, spaced 6,720 seconds apart. Known recorded anchors
omitted from a sparse calibration list are inserted by timestamp order. A break
inside that interval blocks the prefix; a later closure does not shift earlier
proposals. A sparse list whose first anchor skips an earlier recorded season
cannot backdate the prefix. No delay is spread across missing history.

| Status | Meaning |
| --- | --- |
| recorded | Existing nonconflicting scheduled kickoff, retained separately. |
| estimated_unbounded | One-sided cadence hypothesis; uncertainty has no established bound. |
| conflicting | Conflicting recorded timing or different proposed dates; no estimate selected. |
| blocked | Nearest calibration evidence fails the checks; no estimate selected. |
| unavailable | No qualifying older-prefix evidence; no estimate selected. |

All valid candidate lists contribute proposals. Disagreement never gets averaged
or silently resolved by selecting the longest list. Agreement between repeated
archive files is not independent confirmation. A plausible date, including a
date before October 18, is still an estimate. No confidence interval or exact
market closure duration is inferred.

Partial season coverage does not imply a champion. Results, missing matchdays,
prices, champions, season links, balances and settlements are never generated.
The report leaves financial eligibility false for all rows because this timing
review alone cannot certify financial replay, even for recorded seasons.

Review the real output first, then validate qualifications and financial replay
under issue #70. A future explicitly modeled research path must preserve this
provenance and uncertainty in overlap decisions and daily tables. This command
does not insert dates into MongoDB, alter the dashboard benchmark, or deploy to
production. Production evidence transfer remains issue #71.
