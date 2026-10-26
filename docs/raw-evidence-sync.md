# Raw evidence and local replay

The default `python3 engine/local/sync.py --ssh user@server` now transfers:

- Compressed exact provider responses, URLs, checksums and original request/receipt times from the evidence volume.
- A strategy definition/version/code-fingerprint snapshot and simulation capital setting from the production API.
- Permanent operator market-blockage records from MongoDB using the read-only development account.

It does not download production normalized captures, season checkpoints, collector queues, stakes, settlements, portfolios, calculated profits or Redis caches. SSH reads files and exports allowlisted metadata; it never restores or restarts production. Deploy the new API first: source and local strategy code must match or replay refuses activation.

Raw archives and `strategy.json` remain in `backups/raw-sync/<timestamp>/`. They are permanent source evidence and must remain ignored by Git. Historical kickoff timestamps remain inside their original provider bodies, independently of response receipt times. HTTP errors are preserved too.

Local replay has no provider network calls. It reconstructs catalog links, fixture/results, prices and verified standings into a new timestamped local MongoDB database, then switches the dashboard only after validation. Strategy/settings fingerprints reject incompatible local code; the recorded capital is applied locally. Corrupt archives fail closed. Failed imports leave previous databases and raw files intact.

Local MongoDB reconstruction is a disposable materialized view, not an additional permanent source. Redis calculations retain the existing recent-seven-day/requested-historical-one-day caching policy. No automatic MongoDB TTL or production deletion is introduced in this transition: the existing normalized production copies remain until raw-only reconstruction has been verified on the real dataset. After a successful raw sync, retain the active local snapshot and only the previously active snapshot. Older timestamped local databases and raw/legacy sync folders are removed; unrelated databases and production volumes are excluded. The transport tar is removed after validation/activation to avoid storing the same raw evidence twice. Failed syncs never trigger cleanup.

`--legacy-normalized` explicitly selects the previous snapshot transfer for recovery during migration. It is not the default. Existing `--activate` still validates a previously built timestamped local database.

Incomplete raw coverage is not repaired with invented metadata. Missing standings, fixtures or prices remain visible; no source data is deleted to hide gaps. Operator-confirmed blockages remain explicit rather than inferred from HTTP failures.

The rebuilt dashboard describes the raw snapshot. It does not copy production scheduling/retry state, which is temporary operational data.

## Production isolation

The default Docker target is `production` and removes the raw rebuild command and importer classes from its filesystem. Local Compose explicitly builds target `local`. The production API retains only the read-only strategy snapshot helper used over SSH. The rebuild command is not an HTTP route. Runtime database/name guards still protect the local target. CI verifies the command is absent from the production image and reconstructs synthetic evidence using the local image.


## Sync while collection continues

The local downloader first freezes a read-only list of raw file paths, compressed-file sizes and SHA-256 checksums. It passes that null-delimited list to remote GNU tar with recursion disabled. Directories are not archive entries, so new captures changing a directory no longer cause `file changed as we read it` failures.

Files modified in the latest 60 seconds are deferred to the next sync to avoid copying a response still being written by an older collector. The downloaded list is retained as `raw-files.json`. After transfer, sync checks membership, size and compressed-file hashes as well as the existing decompressed-body hashes. A changed, missing or damaged file still stops activation; no tar error is ignored. The previous local snapshot remains active on failure.

This fix only requires updating the local checkout. The manifest is read through the existing production PHP runtime over SSH; production services and collectors need no restart or rebuild.
