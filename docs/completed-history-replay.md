# Avoid normalizing completed history twice

The measured incremental sync took 844.823 seconds. Of the history phase,
185.564 seconds were importer work and 2.026 seconds were the final integrity
verification. It reused 65,404 raw files, downloaded 401 (5,679,337 bytes), and
used a compatible completed seed. Dashboard preparation was another 249.5 seconds
and remains a separate performance target.

The local importer previously normalized every source in a global validation pass,
then normalized each season again before reading its completed import ledger.
Completed ledger rows only need to restore their pinned plan; that second
normalization was unused. The importer now performs it only for missing/prepared
season plans that actually need comparison and planning.

All sources still pass manifest/file hashes, the full global identity/semantic
validation pass, archive preservation and archive checksum validation. Missing
completed materialization/checkpoints are restored as before. The separate final
fixture/price/provenance integrity audit is unchanged and still blocks activation
if pinned materialization differs. A changed source/package cannot skip validation
because its previous ledger was complete.

The final import summary now reports `source_files` and `source_parses`. These
count semantic normalization calls, not all filesystem reads or checksum passes.
A fully completed package needs one normalization per file rather than two. New
or prepared seasons still get the required second pass. Mixed packages report
`source_files` plus the files belonging to unfinished seasons. The native MongoDB
smoke checks fresh/repeated/prepared imports, missing materialization restoration,
changed raw bytes, cross-season identity conflicts and corrupted archived bodies.

No strategy or raw-parser fingerprint changes are introduced. Updating this code
alone does not force a full raw rebuild. Local normal sync builds the updated API
image and imports automatically; no production deployment is needed for this
local-only command. Do not stop an in-progress sync to install the optimization.

After updating, use an ordinary sync and retain its new timing report. Compare
`history_import` (previously 185.564s), source parse counts, seed mode and package
coverage. No wall-clock speedup is claimed before a comparable Mac run: file and
archive hashing remain, and fresh/prepared imports keep their full work. Do not
expect the entire 185 seconds to disappear or assume another phase is optimized.
