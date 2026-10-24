# One collector environment, read-only local research

After deploying the event-order milestone, production is the sole collector.
Development reads its data through SSH, with a dedicated MongoDB read-only user.
Do not use the production application/admin user in development. No database
exports or duplication are required, and local Redis stays independent.

## Server: create one read-only user

From the server checkout, authenticate as administrator (prompt one), then enter
and save a new development-user password (prompt two):

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec mongodb \
  mongosh --username kiotrad_admin --password --authenticationDatabase admin \
  --quiet --eval 'db.getSiblingDB("kiotrad").createUser({user:"kiotrad_dev",pwd:passwordPrompt(),roles:[{role:"read",db:"kiotrad"}]})'
```

If the user already exists, inspect its roles rather than re-creating it. Keep
MongoDB loopback-only on server port 27018 and keep its existing authentication.

## Mac: stop writers before changing the connection

Inside the local checkout:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml stop \
  collector-english collector-bundesliga collector-euro paper-worker notification-worker
```

Leave production collectors running. Local volumes can remain intact; never use
`down -v`. In another Mac terminal keep this SSH tunnel open:

```bash
ssh -N -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
  -L 127.0.0.1:27718:127.0.0.1:27018 root@YOUR_SERVER_IP
```

For Docker Desktop on macOS, the private local `.env` URI uses
`host.docker.internal:27718`, username `kiotrad_dev`, database/authSource `kiotrad`.
URL-encode the password before placing it in the URI. Do not paste the URI into
chat or source control. Set `SIMULATION_CACHE_ENABLED=true` and `REDIS_HOST=redis`.

Start only local read services (no collector/paper/notification profiles):

```bash
docker compose --env-file engine/.env -f engine/compose.yaml up -d --build --no-deps redis api web
```

A missing tunnel produces an unavailable-data error; do not silently switch to a
second crawler. Stop/restart the tunnel when needed. Linux Docker requires a
separate host-network/tunnel arrangement; do not expose the forwarded MongoDB
port publicly to work around routing. This workflow is for the user's Mac.

Read-only MongoDB permissions prevent development from altering captures,
queues, decisions or balances even if a write command is accidentally run.
Historical/current calculations use local temporary Redis and do not require
write privileges on production. New migrations, strategies, alerts and collection
changes are tested against isolated fixture databases/CI before deployment.
Cloudflare Access and CD are separate milestones, not substitutes for SSH or DB
authentication here.
