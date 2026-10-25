# Recover archived season links

`missing_linked_previous_season` means the simulation lacks an accepted predecessor link, not necessarily match results. Historic snapshots from before catalog persistence can contain results without those links.

On production, after deploying this command:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  exec -T api php artisan provider:recover-season-links all
```

This requests each league's provider season catalog and inserts adjacent seasons ordered by provider start timestamps. It does not infer order from numeric season IDs, change collector state, stop collectors, or overwrite existing links. Invalid or ambiguous catalogs fail that league. Only seasons still listed by the provider can be recovered this way; seasons outside that catalog need older provider evidence.

Then refresh the local snapshot:

```bash
python3 engine/local/sync.py --ssh root@169.58.227.92
```

The download should now include `provider_catalog_links`. Simulation cache fingerprints include these links, so affected runs recompute. A verified prior champion, fixtures, results and odds remain separate requirements; links alone do not guarantee a priced simulation.
