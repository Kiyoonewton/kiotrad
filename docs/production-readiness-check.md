# Production acceptance after history import

This diagnostic command reads production over an existing SSH connection and
writes review files locally. It has no apply, deploy, rebuild or cache-flush mode.
It can run independently of the Overview projection UI change.

From the Mac repository, after merging:

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/recovery/check_production_readiness.py --ssh root@169.58.227.92
```

SSH must already work with the host key trusted and without an interactive
password prompt. No MongoDB password is requested: the diagnostic uses the
existing production API container and its configured connection. A host alias is
also accepted; SSH options cannot be injected through this argument. The script
requires production's `kiotrad` database, async dashboard, and existing container
`kiotrad-api-1` with the reviewed-history import classes deployed.

The capture reads API readiness and Overview through the internal web proxy,
then requests the reusable research scenario twice at production's configured
base capital, qualification and baseline financial rules. It reads Overview again
and inspects the current keys' valid last-good files. It does not delete Redis,
stop workers, simulate an outage, force preparation, or change saved settings.
Ordinary scenario GETs can populate disposable Redis calculation caches.

Completed package proofs determine the expected season records and additions.
Each expected season's pinned proof and action hash must match; its immutable
capture and checkpoint are verified using the existing read-only import verifier.
Missing/changed materializations or unfinished import records fail the audit.
This is not a fresh comparison against every original raw file or backup archive.

Local integer audits reconcile funded attempts, stake rounding, payouts, run nets,
capital at entry, overlaps, equity and Lagos daily totals for each returned view.
The monitored Overview has no request settings field, so a local copy is annotated
with the captured configuration while exposed financial-rule fields are checked.
Scenario responses must return those exact settings themselves. Every ledger is
audited independently: Overview and prepared research can have different evidence
cutoffs and need not have identical balances. No browser capital projection is used.

A fresh ready dashboard, ready preparation state, two successful reads of each
view, available last-good files, valid imports and reconciled ledgers are required
for PASS. Default dashboard freshness tolerance is 180 seconds; a future timestamp
more than five seconds beyond capture fails. This is point-in-time evidence, not
proof that the dashboard never becomes unavailable. No outage test is performed.

The printed destination contains `snapshot.json`, `report.json`, `runs.csv`,
`daily.csv` and (when present) `issues.csv`. Reports pin captured bytes and collector
hash. Keep them private and out of Git; they include stored run details, not
credentials. Failed readiness still produces diagnostic tables when a structured
capture is available. Transport/bootstrap failures are sanitized and do not print
remote stderr. Existing report folders are never overwritten.

Re-audit a saved capture without contacting production:

```bash
python3 -u engine/recovery/check_production_readiness.py \
  --snapshot recovery/production-readiness-YOUR_TIMESTAMP/snapshot.json
```

PASS supports issue #71 acceptance without certifying historical source accuracy,
pre-match availability, modeled settlement times or future profitability. Keep
issue #67's local sync-button acceptance separate.
