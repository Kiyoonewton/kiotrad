# Historical odds recovery

Archive bootstrap collected fixtures and results with zero odds rounds. Existing seasons can therefore qualify for simulation without usable prices.

After deployment, recover an explicit season on production:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php artisan provider:recover-odds virtual-bundesliga 3148961 3148933
```

Use `--from-day=1 --to-day=1` to test one round first. Other configured leagues use the same command with their own season IDs. Complete settled seasons are required. Each successful round is saved before the next request; rerunning skips rounds with all eight saved prices and retries failures. Do not run concurrent recoveries for the same season.

Captures are additive and keep actual retrieval timestamps. Archived odds are explicitly not verified pre-match prices. Research replay may use them with its existing timing note; strict trading decisions remain unchanged. No portfolio, collector state or provider current checkpoint is modified. The season checkpoint advances to invalidate research caches. Existing standings captures remain available.

Failed or invalid rounds are reported and the command exits unsuccessfully after attempting the remaining requested rounds. No unavailable market is assumed to be an operator-confirmed blockage. Raw responses use the existing archive mechanism.

Then sync the local snapshot and allow the dashboard to refresh:

```bash
python3 engine/local/sync.py --ssh root@169.58.227.92
```

To scan every stored season in all three leagues:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php artisan provider:recover-odds-all
```

Pass `virtual-football`, `virtual-bundesliga` or `virtual-euro` to limit the scan. Both matchday options are supported. The plan uses stored capture season IDs scoped by league, not season links or numeric ID gap guesses. Each season delegates to the resumable single-season recovery; failed/incomplete seasons do not stop later seasons. A nonzero exit reports remaining failures/deferred work. Re-run the same command after disruption: saved rounds are durable progress and are skipped. Incomplete/current seasons remain deferred until settled; no data is fabricated.

This is a manual batch, not an extra live collector or scheduled retry worker. Normal live collection continues independently. Avoid running overlapping recovery batches.
