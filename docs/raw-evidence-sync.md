# Raw evidence and local replay

The default `python3 engine/local/sync.py --ssh user@server` now transfers:

- Compressed exact provider responses, URLs, checksums and original request/receipt times from the evidence volume.
- A strategy definition/version/code-fingerprint snapshot and simulation capital setting from the production API.
- Permanent operator market-blockage records from MongoDB using the read-only development account.

It does not download production normalized captures, season checkpoints, collector queues, stakes, settlements, portfolios, calculated profits or Redis caches. SSH reads files and exports allowlisted metadata; it never restores or restarts production. Deploy the new API first: source and local strategy code must match or replay refuses activation.

Raw archives and `strategy.json` remain in `backups/raw-sync/<timestamp>/`. They are permanent source evidence and must remain ignored by Git. Historical kickoff timestamps remain inside their original provider bodies, independently of response receipt times. HTTP errors are preserved too.

Local replay has no provider network calls. It reconstructs catalog links, fixture/results, prices and verified standings into a new timestamped local MongoDB database, then switches the dashboard only after validation. Strategy/settings fingerprints reject incompatible local code; the recorded capital is applied locally. Corrupt archives fail closed. Failed imports leave previous databases and raw files intact.

Local MongoDB reconstruction is a disposable materialized view, not an additional permanent source. Redis calculations retain the existing recent-seven-day/requested-historical-one-day caching policy. No automatic MongoDB TTL or production deletion is introduced in this transition: the existing normalized production copies remain until raw-only reconstruction has been verified on the real dataset. Old local views can then be discarded and rebuilt from retained raw evidence.

`--legacy-normalized` explicitly selects the previous snapshot transfer for recovery during migration. It is not the default. Existing `--activate` still validates a previously built timestamped local database.

Incomplete raw coverage is not repaired with invented metadata. Missing standings, fixtures or prices remain visible; no source data is deleted to hide gaps. Operator-confirmed blockages remain explicit rather than inferred from HTTP failures.

The rebuilt dashboard describes the raw snapshot. It does not copy production scheduling/retry state, which is temporary operational data.
