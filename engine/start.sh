#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

if [ ! -f engine/.env ]; then
    (umask 077; cp engine/.env.example engine/.env)
fi

docker compose --env-file engine/.env -f engine/compose.yaml build

if grep -q '^APP_KEY=$' engine/.env; then
    app_key=$(docker compose --env-file engine/.env -f engine/compose.yaml run --rm --no-deps api \
        php -r 'echo "base64:".base64_encode(random_bytes(32));')
    (umask 077; sed "s|^APP_KEY=$|APP_KEY=$app_key|" engine/.env > engine/.env.tmp)
    chmod 600 engine/.env.tmp
    mv engine/.env.tmp engine/.env
fi

docker compose --env-file engine/.env -f engine/compose.yaml up -d --wait
printf '\nKioTrad is ready at http://localhost:%s\n' "$(sed -n 's/^WEB_PORT=//p' engine/.env)"
