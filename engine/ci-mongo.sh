#!/bin/sh
set -eu
[ "${CI:-}" = "true" ] || { echo 'CI-only fixture setup.' >&2; exit 1; }
cd "$(dirname "$0")/.."
[ ! -f engine/.env ] || { echo 'Refusing an existing environment.' >&2; exit 1; }
umask 077
cp engine/.env.example engine/.env
# Synthetic credentials for an isolated, disposable CI database only.
python3 - <<'PY'
from pathlib import Path
p = Path('engine/.env')
s = p.read_text().replace('MONGODB_URI=mongodb://mongodb:27017',
    'MONGODB_URI=mongodb://ci_fixture:ci_fixture_password@mongodb:27017/?authSource=admin')
p.write_text(s)
PY
docker compose --env-file engine/.env -f engine/compose.yaml up -d --wait mongodb
docker compose --env-file engine/.env -f engine/compose.yaml exec -T mongodb \
  mongosh --quiet --eval 'db.getSiblingDB("admin").createUser({user:"ci_fixture",pwd:"ci_fixture_password",roles:[{role:"root",db:"admin"}]})'
