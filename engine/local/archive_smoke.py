"""Synthetic CI-only Database Tools archive/config round trip; no SSH/server access."""
import json
import importlib.util
import os
import secrets
import subprocess
import tempfile
from pathlib import Path

if os.environ.get("CI") != "true":
    raise SystemExit("This synthetic smoke check is restricted to CI.")
root = Path(__file__).resolve().parents[2]
command = ["docker", "compose", "--env-file", str(root / "engine/.env"),
           "-f", str(root / "engine/compose.yaml"), "exec", "-T", "mongodb"]
source = "kiotrad_sync_source_smoke"
target = "kiotrad_local_ci_smoke"
password = secrets.token_hex(24)
admin_uri = "mongodb://ci_fixture:ci_fixture_password@127.0.0.1:27017/?authSource=admin"

def shell(script):
    subprocess.run(command + ["mongosh", "--quiet", "--nodb", "--file", "/dev/stdin"],
                   input=script.replace("new Mongo()", "new Mongo(" + json.dumps(admin_uri) + ")").encode(), check=True, stdout=subprocess.DEVNULL)

try:
    shell(f'''
const d=new Mongo().getDB({json.dumps(source)});
d.createUser({{user:"sync_fixture",pwd:{json.dumps(password)},roles:[{{role:"read",db:{json.dumps(source)}}}]}});
d.provider_captures.insertOne({{_id:"synthetic",at:new Date("2026-10-25T00:00:00Z"),value:NumberLong("123")}});
''')
    config = json.dumps({"uri": f"mongodb://sync_fixture:{password}@127.0.0.1:27017/{source}?authSource={source}"}).encode()
    spec = importlib.util.spec_from_file_location("sync", Path(__file__).with_name("sync.py"))
    sync = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(sync)
    uri = json.loads(config)["uri"]
    discovery = sync.discovery_script(uri).replace('"kiotrad"', json.dumps(source))
    shell(discovery)
    with tempfile.TemporaryFile() as archive:
        subprocess.run(command + ["mongodump", "--config=/dev/stdin", "--db=" + source,
                                  "--collection=provider_captures", "--archive", "--gzip"],
                       input=config, stdout=archive, stderr=subprocess.PIPE, check=True)
        archive.seek(0)
        subprocess.run(command + ["mongorestore", "--uri=" + admin_uri, "--archive", "--gzip", "--nsFrom=" + source + ".*",
                                  "--nsTo=" + target + ".*", "--stopOnError"],
                       stdin=archive, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
    shell(f'''
const d=new Mongo().getDB({json.dumps(target)});
const r=d.provider_captures.findOne({{_id:"synthetic"}});
if (!r || !(r.at instanceof Date) || r.value.toString()!=="123") throw Error("Archive changed BSON data");
''')
    shell(f'''
const d=new Mongo().getDB({json.dumps(target)});
d.collector_states.insertOne({{_id:"synthetic-league"}});
for (const name of ["season_checkpoints","provider_checkpoints"]) {{
  d.getCollection(name).insertOne({{_id:"synthetic-reference",capture_id:"synthetic"}});
}}
''')
    shell(sync.validation_script(target, "2026-10-25T00:00:00Z"))
    print("Database Tools stdin configuration, compressed archive and isolated namespace restore passed.")
finally:
    shell(f'''
const c=new Mongo();
c.getDB({json.dumps(source)}).dropAllUsers();
c.getDB({json.dumps(source)}).dropDatabase();
c.getDB({json.dumps(target)}).dropDatabase();
''')
