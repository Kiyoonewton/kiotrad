# Start server capture before dashboard deployment

This additive change requires no database migration or deletion. Use a dedicated
KioTrad checkout and the `kiotrad` Compose project. Never attach `my_trade` storage.
Existing local/Mac history is not automatically transferred to this server.

Check the destination before cloning or starting:

```bash
cd /root/kiyoo
test ! -e kiotrad
docker compose ls
ss -ltnp | rg ':27018\b' || true
df -h /root/kiyoo
free -h
```

If `rg` is unavailable use `grep ':27018'`. Stop if another KioTrad project is
already running or port 27018 is occupied. Do not stop other applications to free
ports; change `MONGO_PORT` in KioTrad's private `engine/.env` instead. Check existing
KioTrad mounts before replacing any existing deployment.

For a fresh destination, clone the reviewed deployment branch, then:

```bash
cd /root/kiyoo/kiotrad
sh engine/start-capture.sh
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors ps
docker compose --env-file engine/.env -f engine/compose.yaml logs --tail=30 \
  collector-english collector-bundesliga collector-euro
```

Only MongoDB and three collectors start. No dashboard, paper configuration or
paper worker is needed to preserve source data. Host Node/PHP are not required.
MongoDB is loopback-only; collectors have no published ports. Restart policies
resume services when Docker restarts; the server must have Docker enabled at boot.

Verify each league has recent captures, then repeat after a few minutes:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec -T mongodb \
  mongosh kiotrad --quiet --eval 'db.provider_captures.aggregate([
    {$group:{_id:"$competition",captures:{$sum:1},latest:{$max:"$observed_at"}}},
    {$sort:{_id:1}}
  ]).forEach(printjson)'
docker compose --env-file engine/.env -f engine/compose.yaml exec -T collector-english \
  sh -c 'find storage/app/private/provider-evidence/raw -type f | wc -l'
```

Raw HTTP response bodies are stored in compressed private files under the existing
`kiotrad_provider_evidence` volume, partitioned by UTC receipt date. Envelopes include
URL, request/receipt timestamps, HTTP status, body hash and base64 exact response
bytes. Successful and invalid/error HTTP responses are saved before normalization.
Connection failures have no response body; persistent gap state handles retries.
Archive write failures fail collection rather than silently losing raw evidence.
There is no expiration or automatic deletion. Existing normalized Mongo captures
remain immutable; old raw responses not previously retained cannot be invented.

Read an archived envelope with `gzip -dc FILE.json.gz`; decode `body_base64` to get
the original bytes. These files are private data, not web-served assets.

The 80 GB planning budget is not a filesystem quota. Check MongoDB and evidence
volume growth plus host free space regularly. Automated storage/Slack alerts,
resource tuning and off-disk backup are separate pending work. Never run
`docker compose down -v` or volume pruning against this deployment.
