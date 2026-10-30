# Research cache memory and worker locks

Calculation Redis is disposable and bounded at 512 MB by default, with a 640 MB
container ceiling. Redis overhead needs headroom beyond maxmemory. An optional
REDIS_CACHE_MAXMEMORY setting changes the data limit; adjust the container ceiling
as well before raising it. Raw evidence and MongoDB are unchanged.

A separate redis-locks service has a 64 MB data limit, a 96 MB container ceiling
and noeviction. It exposes no host port. Laravel's Redis lock connection targets
this server, so calculation-cache pressure cannot delete ownership leases. Merely
using another logical database on the same Redis server would not provide this
protection. Keep REDIS_CACHE_LOCK_CONNECTION=locks if overriding the default.
An unavailable/full lock server fails closed; it does not permit parallel owners.
Existing leases still expire normally; server loss or a one-hour expiry can still
cause lease_lost and safely prevent publication.

Large simulation, prepared-evidence and dashboard arrays use versioned gzip
compression. Readers accept the old array format during deployment. Corrupt
entries become cache misses; source evidence and exact integer money are unchanged.
Scenario results use 16 replaceable slots per prepared scope, at most 4 MiB of
compressed data each, with full fingerprint validation on reads. Slot collisions
only cause recalculation. Entries still expire after one day. Oversized results
are returned normally but are not cached. Calendar coverage replaces each season's
old source revision instead of creating a key for every revision.

Existing legacy scenario keys expire within their previous one-day TTL; old
calendar revisions expire within seven days. No FLUSHALL or source deletion is
needed. Cache limits provide the bound while older keys age out. A new prepared
scope (database/policy) has its own slots; old scopes expire under normal TTLs.

## Deploy once, on local or production

Pull main first. Stop api and dashboard-worker, then start both Redis services
with `up -d --wait redis redis-locks`. This deployment recreates calculation Redis because
its configuration changed, dropping only disposable calculations. It is important
to stop old preparation workers first: their locks lived on the old server.
Rebuild api and dashboard-worker, and recreate web alongside API to refresh its
upstream address. Start collectors/paper/notification workers with the new code and
connection settings if they use Redis locks. No database volumes are removed.

Local sync starts both Redis services before preparing staging views. Subsequent
activations do not force-recreate either service.

The first cold preparation can still take time. Successful preparation publishes
`workbench: prepared`; a later unchanged source reports `workbench: unchanged`.
New provider evidence correctly causes another preparation on production.

CI forces real result-cache eviction and verifies that an active worker lease
remains owned, rejects a competitor, and can release/reacquire afterwards.
