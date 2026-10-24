#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

# Capture-only startup: no public dashboard, paper balance or trading worker.
if [ ! -f engine/.env ]; then
    (umask 077; cp engine/.env.example engine/.env)
fi
chmod 600 engine/.env
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors build collector-english
if grep -q '^APP_KEY=$' engine/.env; then
    app_key=$(docker compose --env-file engine/.env -f engine/compose.yaml run --rm --no-deps collector-english \
        php -r 'echo "base64:".base64_encode(random_bytes(32));')
    (umask 077; sed "s|^APP_KEY=$|APP_KEY=$app_key|" engine/.env > engine/.env.tmp)
    chmod 600 engine/.env.tmp
    mv engine/.env.tmp engine/.env
fi
docker compose --env-file engine/.env -f engine/compose.yaml --profile collectors \
    up -d --build mongodb collector-english collector-bundesliga collector-euro
printf '\nCapture services started. Check collector logs and database growth before calling deployment verified.\n'
