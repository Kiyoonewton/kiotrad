# Compare an estimated older history with the recorded-only benchmark

This local CLI research path follows the older timing review (#69) and starts
qualification/financial validation (#70). It does not write estimated dates,
champions or links into source collections, update dashboard results, or deploy
history to production. It refuses non-local-mirror databases.

The input is the full `report.json` from `older_timing_proposals.py`, not the CSV.
All estimated dates must still agree with their anchor and listed-step arithmetic.
The first two recorded seasons in each league are loaded to check qualification
chains across the estimated/recorded boundary. Their dates must match current
stored fixture evidence; new recorded timing in an estimated season requires a
fresh audit. Raw response timestamps are never used as kickoffs.

## What the replay assumes

- Older starts use the reviewed dates; known fixtures are spaced 220 seconds by
  matchday. Missing fixtures/results are never manufactured.
- Prefix adjacency is hypothetical and requires exactly 6,720 seconds between
  starts. Recorded-to-recorded links must already be stored as observed rollover
  or provider catalog adjacency. Partial seasons retain their position.
- A calculated champion requires all 240 unique fixtures, 30 complete matchdays,
  16 teams and valid settled H/D/A results. Only a unique points leader is used;
  ties stay unresolved because the archive lacks reliable tie-breaking scores.
  This calculated champion is labeled, not saved as provider-verified standings.
- Next Win uses the previous season's champion, starting after its first win in
  the current season. Next Season uses the older champion's first win in the
  previous season, backing the previous champion from day 1 of the current one.
  Both use the selected qualification threshold. Incomplete champion seasons
  block dependencies; they are never skipped over to pick another champion.
- Actual prices and result observation times come from stored captures. Missing
  prices and confirmed operator blocks keep their normal replay treatment.
  Estimated kickoff dates do not turn late archived quotes into verified
  pre-match observations.
- New older/boundary candidates use historical sizing minima from earlier loaded
  seasons only. Existing recorded candidates, including their original minima,
  are held unchanged. This controls the comparison; it is not a complete
  recalibration of all later recorded-season minima from the longer history.
- Both scenarios run through the existing shared-capital engine with identical
  settings, including fixed/compound capital, allowance, attempt limit, profit
  stop and the 180/201/220-second settlement sensitivity. The latter varies
  settlement offset, not the unknown error in older kickoff dates.

Unknown timing uncertainty has no measured bound. Every newly added run includes
its timing dependencies, source hashes and calculated-champion basis. Overlap
involving these candidates is labeled hypothetical; every financial result in
the combined scenario is conditional on the assumptions, including later gains
changed by compounding. This output does not establish live profitability.

## Run on the Mac

After merging and pulling, build the one-off API image, then run the local
comparison. Choose the same settings as the simulator you want to compare;
this example explicitly uses ₦50,000, day 5, four attempts, ×1 and compounding.

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml build api &&
docker compose --env-file engine/local/.env.local -f engine/local/compose.yaml \
  run --rm --no-deps -T -v "$PWD/recovery:/research:ro" api \
  php -d memory_limit=512M artisan strategy:replay-estimated-history \
  /research/older-timing-review-20261031T092952/report.json \
  --capital=50000 --qualification=5 --attempts=4 --allowance=1 \
  --capital-mode=compound --profit-stop=0 \
  > recovery/estimated-history-comparison.json
```

The one-off process has a 512 MiB PHP memory ceiling for the longer history.
Progress prints to the terminal separately from the JSON result. Standard source
preparation may refresh ordinary derived baselines/caches; no hypothetical
source material is stored. Keep the same database throughout the comparison.
The report pins capture hashes per loaded season, the recorded prepared payload
hash, timing report hash, parent report hashes, database, settings and as-of time.
Output redirection replaces the example comparison file on a repeated run;
choose a new filename if you want to preserve multiple comparisons.

Inspect the summary:

```bash
python3 - <<'PY'
import json
from pathlib import Path
r = json.loads(Path('recovery/estimated-history-comparison.json').read_text())
for name in ('recorded_only', 'with_estimated_history'):
    v = r[name]
    print(name, '| completed runs:', len(v['equity']), '| final balance: NGN', f"{v['balance_minor']/100:,.2f}")
print('Balance difference: NGN', f"{r['difference']['balance_minor']/100:,.2f}")
print('New accepted IDs:', r['difference']['accepted_only_with_estimates'])
print('No longer accepted:', r['difference']['accepted_only_recorded'])
print('Champion/coverage diagnostics:', len(r['season_diagnostics']))
print('Research only; kickoff uncertainty remains unknown.')
PY
```

Review `season_diagnostics` and `estimated_history_exclusions` before interpreting
profit. A later step can expose this comparison in the simulator, after the real
output and boundary behavior are reviewed. The recorded dashboard benchmark and
production transfer (#71) remain separate.

After generating the comparison, run the independent integer ledger checks in
[`history-comparison-audit.md`](history-comparison-audit.md) to inspect run,
attempt, daily and issue tables before interpreting any change in profit.
