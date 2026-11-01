# Measure slow local syncs

Normal raw sync now records its major phases in a local report and prints the five
slowest phases at completion or failure. Use the existing terminal command or
local sync button; no new flag or production deployment is needed.

```
Sync phase: Copying previous local snapshot
Sync phase: Processing raw evidence
...
Sync timing report: .../recovery/sync-timings/TIMESTAMP.json
Sync timing: Preparing dashboard views · ...s
```

The report separates inventory, verified local reuse, transfer, raw verification,
API build, parser check, seed copying, raw processing, blockage copying, history
package recovery, history replay/verification, staging validation, view preparation,
activation and retention. Nested history import/verification operations are also
measured; their times are already included in the history phase, not extra time.
Durations use a monotonic clock. A failed report names the failed phase and retains
completed measurements without storing commands, credentials or exception text.
Unexpected process termination can leave a `running` report at its last checkpoint.

The report records raw file reuse/download counts and transfer bytes, plus whether
staging used a compatible completed seed or required a full rebuild. Full rebuild
reasons include a parser change, no compatible index, or an explicit request.
Raw checks and retention remain unchanged. The sync button can display the more
specific allowlisted phase names, without exposing child command output.

This is measurement, not a claimed speed improvement. The existing delta transfer
and affected-season replay remain in place. No PHP parser/fingerprint changes are
introduced, so installing this instrumentation alone does not force a full rebuild.
A previous 45-minute run could include a one-time parser rebuild; compare an ordinary
incremental run before attributing that duration to every sync.

Reports remain in `recovery/sync-timings`, separate from pruned snapshot folders.
Compare the slowest phase and seed mode before optimizing. If the report cannot be
saved, a generic warning is printed and the existing sync safety checks continue.
These timings cover the raw sync pipeline, including activation/retention, but not
the button helper's additional HTTP readiness polling afterward.
