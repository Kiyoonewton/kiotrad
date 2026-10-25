#!/usr/bin/env python3
"""Mac-only, pull-only provider snapshot into an isolated local Docker project."""
import argparse
from contextlib import contextmanager
import fcntl
from datetime import datetime, timezone
from getpass import getpass
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import subprocess
import sys
from urllib.parse import quote

ROOT = Path(__file__).resolve().parents[2]
LOCAL = ROOT / "engine/local"
ENV = LOCAL / ".env.local"
# Capture immutable documents last so earlier exported checkpoints can resolve.
COLLECTIONS = (
    "collector_states", "provider_season_transitions", "provider_catalog_links",
    "market_blockages", "season_checkpoints", "provider_checkpoints", "provider_captures",
)


def run(args, phase="command", **kwargs):
    result = subprocess.run(args, stderr=subprocess.PIPE, **kwargs)
    if result.returncode:
        detail = ""
        error = (result.stderr or b"").decode(errors="replace")
        for line in error.splitlines():
            if line.startswith("SYNC_ERROR:"):
                try:
                    diagnostic = json.loads(line[len("SYNC_ERROR:"):])
                    stage = diagnostic.get("stage", "unknown")
                    code = diagnostic.get("code", "unknown")
                    if stage in {"connect", "roles", "collections"} and isinstance(code, (int, str)) and re.fullmatch(r"[A-Za-z0-9_-]{1,40}", str(code)):
                        detail = f"; source stage={stage}, code={code}"
                except (ValueError, TypeError):
                    pass
        if not detail:
            for pattern, hint in [("Permission denied", "SSH login denied"), ("No such container", "source container missing"),
                                  ("Authentication failed", "database authentication failed"), ("Connection timed out", "SSH connection timed out")]:
                if pattern.lower() in error.lower():
                    detail = "; " + hint
                    break
        raise RuntimeError(f"{phase}: {args[0]} failed (exit {result.returncode}){detail}. Credentials hidden.")
    return result


def compose(*args, **kwargs):
    return run(["docker", "compose", "--env-file", str(ENV), "-f",
                str(LOCAL / "compose.yaml"), *args], **kwargs)


def guard_local(platform=sys.platform, environ=None):
    environ = os.environ if environ is None else environ
    if platform != "darwin":
        raise RuntimeError("This command is restricted to local macOS development, not the server.")
    if environ.get("DOCKER_HOST", "unix://").startswith(("tcp:", "ssh:", "http:", "https:")):
        raise RuntimeError("Remote Docker endpoints are forbidden.")
    endpoint = run(["docker", "context", "inspect", "--format",
                    "{{.Endpoints.docker.Host}}"], stdout=subprocess.PIPE).stdout.decode().strip()
    if not endpoint.startswith("unix://"):
        raise RuntimeError("Only a local Unix-socket Docker context is allowed.")


def verify_destination():
    container = compose("ps", "-q", "mongodb", stdout=subprocess.PIPE).stdout.decode().strip()
    if not container:
        raise RuntimeError("Local MongoDB did not start.")
    info = json.loads(run(["docker", "inspect", container], stdout=subprocess.PIPE).stdout)[0]
    labels = info["Config"].get("Labels", {})
    mounts = info.get("Mounts", [])
    if labels.get("com.docker.compose.project") != "kiotrad-local" or labels.get("kiotrad.local-only") != "true":
        raise RuntimeError("Destination is not the isolated local project.")
    if not any(m.get("Name") == "kiotrad_local_mongo_data" and m.get("Destination") == "/data/db" for m in mounts):
        raise RuntimeError("Unexpected destination volume; refusing all imports.")


def write_environment(database):
    if not re.fullmatch(r"kiotrad_local(?:_[0-9TZ]+)?", database):
        raise RuntimeError("Invalid local database name.")
    content = ENV.read_text() if ENV.exists() else (LOCAL / ".env.example").read_text()
    lines = [line for line in content.splitlines() if not line.startswith("LOCAL_DATABASE=")]
    if "APP_KEY=\n" in content:
        import base64
        lines = ["APP_KEY=base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
                 if line == "APP_KEY=" else line for line in lines]
    lines.append("LOCAL_DATABASE=" + database)
    temp = ENV.with_suffix(".pending")
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as stream:
        stream.write("\n".join(lines) + "\n")
    temp.replace(ENV)


def ssh_command(server, remote):
    if not re.fullmatch(r"[A-Za-z0-9_.@-]+", server) or server.startswith("-"):
        raise RuntimeError("Use an SSH alias or user@hostname, without extra arguments.")
    return ["ssh", "-T", "-o", "ConnectTimeout=15", "-o", "ServerAliveInterval=15",
            "-o", "ServerAliveCountMax=3", server, shlex.join(remote)]


def source_command(server, collection):
    if collection not in COLLECTIONS:
        raise RuntimeError("Collection not allowlisted.")
    remote = ["docker", "exec", "-i", "kiotrad-mongodb-1", "mongodump",
              "--config=/dev/stdin", "--db=kiotrad", "--collection=" + collection,
              "--archive", "--gzip"]
    return ssh_command(server, remote)


def discovery_script(uri):
    return f"""
let stage="connect";
try {{
  const source=new Mongo({json.dumps(uri)}).getDB("kiotrad");
  stage="roles";
  const roles=source.runCommand({{connectionStatus:1}}).authInfo.authenticatedUserRoles;
  if (!roles.length || roles.some(r=>r.role!=="read" || r.db!=="kiotrad")) {{
    console.error('SYNC_ERROR:'+JSON.stringify({{stage,code:"read_only_role_required"}})); quit(2);
  }}
  stage="collections";
  print(JSON.stringify(source.getCollectionNames().filter(n=>{json.dumps(COLLECTIONS)}.includes(n))));
}} catch (e) {{
  console.error('SYNC_ERROR:'+JSON.stringify({{stage,code:Number.isInteger(e.code)?e.code:(["TypeError","ReferenceError"].includes(e.name)?e.name:"script_error")}}));
  quit(1);
}}
"""


def source_collections(server, uri):
    print("Checking source login and read-only permissions...", flush=True)
    result = run(ssh_command(server, ["docker", "exec", "-i", "kiotrad-mongodb-1",
                 "mongosh", "--quiet", "--nodb", "--file", "/dev/stdin"]),
                 phase="source discovery", input=discovery_script(uri).encode(), stdout=subprocess.PIPE)
    names = json.loads(result.stdout)
    if not set(["collector_states", "season_checkpoints", "provider_checkpoints", "provider_captures"]).issubset(names):
        raise RuntimeError("Required source collections missing; no active local data changed.")
    return names


def validation_script(database, started):
    return f'''
const d=db.getSiblingDB({json.dumps(database)});
if (!d.getName().startsWith("kiotrad_local_")) throw Error("Unsafe database");
const count=d.provider_captures.countDocuments();
if (!count) throw Error("No provider captures restored");
for (const name of ["collector_states","season_checkpoints","provider_checkpoints"]) {{
  if (!d.getCollection(name).countDocuments()) throw Error("Required metadata missing: "+name);
}}
for (const name of ["season_checkpoints","provider_checkpoints"]) {{
  d.getCollection(name).find().forEach(row => {{
    if (!row.capture_id || !d.provider_captures.findOne({{_id:row.capture_id}}))
      throw Error("Checkpoint missing capture: "+name);
  }});
}}
d.local_sync_status.updateOne({{_id:"source"}},{{$set:{{
  started_at:{json.dumps(started)},completed_at:new Date().toISOString(),
  source_database:"kiotrad", captures:count, mode:"manual_full_snapshot"
}}}},{{upsert:true}});
print("Validated "+count+" local captures");
'''


@contextmanager
def local_lock():
    with (LOCAL / ".sync.lock").open("a") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError("Another local sync is already running.") from None
        try:
            yield
        finally:
            fcntl.flock(lock, fcntl.LOCK_UN)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--ssh", help="SSH alias or user@server")
    source.add_argument("--activate", help="Validate and activate an already restored local snapshot without downloading")
    args = parser.parse_args()
    guard_local()
    with local_lock():
        if args.activate:
            if not ENV.exists():
                raise RuntimeError("Local configuration missing; perform an initial sync first.")
            compose("up", "-d", "--wait", "mongodb")
            verify_destination()
            activate_snapshot(args.activate, datetime.now(timezone.utc).isoformat())
        else:
            perform_sync(args.ssh)


def perform_sync(server):
    os.umask(0o077)
    if not ENV.exists():
        write_environment("kiotrad_local")
    compose("up", "-d", "--wait", "mongodb")
    verify_destination()
    password = getpass("Production kiotrad_dev password (read-only): ")
    if not password:
        raise RuntimeError("Empty password rejected.")
    uri = "mongodb://kiotrad_dev:" + quote(password, safe="") + "@127.0.0.1:27017/kiotrad?authSource=kiotrad"
    available = source_collections(server, uri)
    config = json.dumps({"uri": uri}).encode()
    started = datetime.now(timezone.utc).isoformat()
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    database = "kiotrad_local_" + stamp
    backup = ROOT / "backups/local-sync" / stamp
    backup.mkdir(parents=True, exist_ok=False)
    # Only remote operation: read-only mongodump. No remote restore or restart.
    for collection in COLLECTIONS:
        if collection not in available:
            continue
        print("Downloading " + collection + "...", flush=True)
        archive = backup / (collection + ".archive.gz")
        with archive.open("wb") as output:
            run(source_command(server, collection), phase="download " + collection, input=config, stdout=output)
        if not archive.stat().st_size:
            raise RuntimeError("Empty archive; previous local database remains active.")
        with archive.open("rb") as source:
            compose("exec", "-T", "mongodb", "mongorestore", "--archive", "--gzip",
                    "--nsFrom=kiotrad.*", "--nsTo=" + database + ".*", "--stopOnError",
                    stdin=source, stdout=subprocess.DEVNULL)
    activate_snapshot(database, started)


def activate_snapshot(database, started):
    if not re.fullmatch(r"kiotrad_local_[0-9TZ]+", database):
        raise RuntimeError("Only timestamped local snapshot databases can be activated.")
    print("Validating local snapshot...", flush=True)
    compose("exec", "-T", "mongodb", "mongosh", "--quiet", "--file", "/dev/stdin",
            phase="validate local snapshot", input=validation_script(database, started).encode())
    # Switch only after every download/import/validation succeeds. Keep old DBs.
    previous = ENV.read_bytes()
    compose("stop", "api", "dashboard-worker")
    try:
        write_environment(database)
        compose("up", "-d", "--build", "--no-deps", "redis", "api", "web", "dashboard-worker")
    except BaseException:
        ENV.write_bytes(previous)
        compose("up", "-d", "--no-deps", "api", "web", "dashboard-worker")
        raise
    print("Local snapshot ready: http://localhost:5174")
    print("Previous local databases and downloaded archives retained. No continuous SSH tunnel needed.")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, KeyboardInterrupt) as error:
        print("Sync stopped: " + str(error), file=sys.stderr)
        sys.exit(1)
