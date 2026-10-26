# Recover legacy local odds into production

Use exact plain JSON responses from the old repository's **raw folder only**.
Do not connect to, restore or modify the old project's database. This workflow
makes no provider requests, does not restart collectors and never edits the
original source files. Do not run simultaneous import commands.

## 1. Stage on the Mac after pulling this code

```bash
cd "$HOME/Developer/kiotrad"
python3 engine/recovery/package_legacy_odds.py \
  "$HOME/Developer/my_trade-multileague/raw" \
  "$PWD/recovery/legacy-odds" \
  --seasons=3147366,3147685
```

Use a new destination for each package. Omit `--seasons` to stage all recognized
legacy odds responses after the first targeted batch has been verified. Files
are discovered from their payload routes, not guessed names/hash suffixes.
The staging manifest preserves filenames, SHA-256 checksums and season/round
identities. It makes no claim that filesystem modification time is retrieval time.

## 2. Upload the package

```bash
tar -czf /tmp/kiotrad-legacy-odds.tar.gz -C recovery/legacy-odds files manifest.json
scp /tmp/kiotrad-legacy-odds.tar.gz root@169.58.227.92:/root/kiyoo/kiotrad/
```

On production, pull the same main revision, build the importer image, and extract
the uploaded package into a new recovery directory. Existing collectors can keep
running their current containers during the image build and one-off import:

```bash
cd /root/kiyoo/kiotrad
git pull --ff-only --no-rebase origin main &&
docker compose --env-file engine/.env -f engine/compose.yaml build collector-euro
mkdir -p recovery/legacy-odds
tar -xzf kiotrad-legacy-odds.tar.gz -C recovery/legacy-odds
```

The tar contains files created locally by step 1. Do not extract an untrusted
third-party archive. Use a fresh directory for a different package.

## 3. Read-only server validation

```bash
docker compose --env-file engine/.env -f engine/compose.yaml \
  run --rm --no-deps -T \
  -v "$PWD/recovery/legacy-odds/files:/legacy-package/files:ro" \
  -v "$PWD/recovery/legacy-odds/manifest.json:/legacy-package/manifest.json:ro" \
  collector-euro php artisan provider:import-legacy-odds /legacy-package/files \
  > recovery/legacy-odds-dry-run.jsonl
cat recovery/legacy-odds-dry-run.jsonl
```

Despite the service name, league identification comes from each payload and must
match configured provider tournament IDs. The manifest must match copied bytes;
each quote must match stored league, season, round, match ID and both team IDs.
Only existing round fixtures are required, so a four-day incomplete season can
still receive prices for those four days. Missing fixtures are reported, not
invented. A dry run does not write to MongoDB or archive storage.

## 4. Apply after reviewing the dry-run report

Repeat step 3 with `--apply` after `/legacy-package/files`, changing the report
filename to `legacy-odds-applied.jsonl`. It imports only missing usable prices.
Existing differing prices are reported as conflicts and never overwritten.
Identical source files are skipped by checksum. Rejected files are reported;
valid files already applied stay durable if another file fails. Rerun safely.

Each accepted source is archived under the shared permanent
`provider-evidence/raw/YYYY/MM/DD/*.json.gz` tree before derived writes. The
legacy envelope retains the exact original bytes/checksum, source filename,
provider payload timestamp, import timestamp and accepted match IDs. HTTP status,
original request time and original receipt time remain **unknown/null** because
the old plain JSON files do not supply trustworthy transport metadata.

For compatibility with research queries, normalized observation/captured dates
use import time and carry `provenance.timestamp_basis=import_time_original_retrieval_unknown`.
This is not presented as historical acquisition or pre-match verification.
Event kickoff remains the existing fixture `time.uts`; no finish time is invented.

## 5. Verify and sync

Rerun price coverage for the affected seasons on production. After deploying the
same code to dashboard/API helpers, refresh derived views and sync local through
`engine/local/sync.py`. Wait at least 60 seconds after import before raw sync so
new archived files pass the snapshot safety lag. Local replay understands legacy
envelopes and imports only their recorded accepted match IDs, so conflicts or
already-priced quotes are not silently added during reconstruction.

The importer updates affected odds-evidence revisions; derived caches/baselines
refresh without flushing unrelated Redis data. No production volume is removed.
Legacy import tracking is disposable; permanent raw envelopes suffice to rebuild
accepted price evidence. Keep the old local originals until coverage is verified.

## Unfunded entry correction

An entry with no valid actual price or a confirmed market block and **zero stake**
is now `unfunded_entry`, with an explicit `entry_issue`. It reserves no capital
and cannot block later candidates. Another placeable candidate at the same entry
can be chosen using the existing estimated-cost ranking. Missing evidence is
still counted as unresolved; no stake, win profit or loss is fabricated.

A run which already placed stakes before a later missing price remains unresolved
with its actual exposure preserved. This patch does not discard losses or claim
full portfolio coverage when evidence is missing. Recovered prices trigger replay
from the beginning and can change subsequent selections and balances.
