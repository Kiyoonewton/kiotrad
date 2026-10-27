# Incremental local reconstruction

Run the usual command on macOS:

```bash
python3 engine/local/sync.py --ssh root@169.58.227.92
```

Raw provider responses remain permanent evidence. MongoDB contains disposable normalized provider views; Redis caches strategy and dashboard calculations. Incremental raw processing does not move permanent evidence into Redis.

The downloader reuses checksum-verified files. Reconstruction seeds a new timestamped local database from the last successfully activated, indexed local database, then indexes new or changed raw files. It replays only affected seasons, including their older fixture, price and standings observations so late evidence is joined correctly. Unchanged seasons are not normalized again. Progress reports show index and per-phase counts, percentages and elapsed seconds.

A local database copy still takes time and disk I/O, and every raw file's compressed checksum is checked. This avoids re-normalizing the full history; it does not eliminate all full-snapshot I/O. Redis dashboard calculations may need a refresh after activation.

The first sync after installing this feature performs one full reconstruction to establish the index. A changed normalizer version or missing previously indexed evidence is rejected rather than mixed with incompatible views. To deliberately reconstruct from all raw files:

```bash
python3 engine/local/sync.py --ssh root@169.58.227.92 --full-rebuild
```

Only local macOS sync invokes this path. Production images exclude the reconstruction command, parser and index. The remote side is read-only. Staging failure leaves the active database and environment unchanged. A retry seeds from the last completed snapshot and safely repeats affected seasons; partially rebuilt staging databases are never activated. Successful activation retains the current and one previous local snapshot.
