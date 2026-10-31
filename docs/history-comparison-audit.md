# Independently audit the history comparison ledger

Issue #70: check the JSON produced by `strategy:replay-estimated-history` without
rerunning Docker, reading MongoDB, changing the dashboard, or touching production.
The Python auditor uses integer minor units and does not invoke the PHP replay.
It pins the exact comparison file hash and refuses existing output folders.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/audit_history_comparison.py \
  recovery/estimated-history-comparison.json \
  --destination "recovery/history-ledger-audit-$(date +%Y%m%dT%H%M%S)"
```

A passing audit prints the settled-run count and net for each scenario. A failing
audit exits nonzero while retaining the diagnostic tables. Malformed, incomplete,
unsupported or non-integer financial input stops the audit without a passing
report. No raw rescan, password or database connection is needed.

Open the output folder in Finder, then open these CSV files:

| File | What to inspect |
| --- | --- |
| runs.csv | Run/team/season/strategy, status, WAT entry/release, stake/payout/net, estimated dependencies, calculated-champion basis and blocker. |
| attempts.csv | Attempt stake/payout, actual scaled odds, fixture ID, result observation, quote capture/provenance, historical sizing minimum and estimated dependency. |
| daily.csv | Accepted settled-run counts and money totals, grouped on Lagos release date for each scenario. |
| issues.csv | Failed check, scenario, run ID, expected value and actual value; created only when issues exist. |
| report.json | Exact integer totals, all diagnostic rows, input checksum and audit limitations. |

CSV amounts ending `_NGN` are exact two-decimal naira values. The JSON preserves
minor units. Scaled odds in the attempts table use 10,000 = decimal odds 1.00.
Daily tables include dates with settled runs only, not zero-run source-coverage
dates. An entire accepted run belongs to its release date, including stakes from
before midnight. Pending stakes appear in the summary as separate exposure;
skipped/unfunded opportunities do not count as realized profit.

## Checks

- Attempt order, whole-naira minimum stake, frozen target and loss-recovery stake
  calculation; payout checked from actual scaled odds and the backed outcome.
- Attempt sums against run stake/payout/net and reserved allowance.
- Unique run IDs; only funded accepted runs can settle or block another entry.
- Accepted windows must not clash, including equal release/entry instants.
  Pending funded runs must prevent further funded entries.
- Capital at entry, fixed/compound sizing capital, allowance versus available
  balance, profit-stop threshold, every equity step and final balance.
- Daily net versus ending capital, comparison balance difference, and the lists
  of completed run IDs added/removed between scenarios.

A PASS establishes internal arithmetic and ledger consistency. It does not
independently prove source results, quote authenticity, champions, qualification,
market availability, or estimated kickoff dates. Source-evidence validation is
still part of #70. The audit treats modeled windows as conditional; unknown
older-timing uncertainty is not resolved by a clean overlap check. Later recorded
runs can also have changed stakes through compounding. Keep this result separate
from any claim of live profitability or permission to import to production.
