# Schedule-driven collection and season reconciliation

## Verified sources

The betting interface at `https://virtual.bet9ja.com/virtual/?s=new` exposes a
betting countdown and can display two upcoming rounds, including next season's
round 1. It is observational evidence, not a guarantee of ticket acceptance.
The moving round selector can hide early rounds; its position is not a fixture
identity. Always join by competition, provider season ID and match ID.

The statistics archive identifies seasons without guessing numeric ID increments:
`https://st-cdn001.akamaized.net/bet9javirtuals/en/1/season/3148905/archive`.
Its fixtures view is `https://st-cdn001.akamaized.net/bet9javirtuals/en/1/season/3148905/fixtures`.
Production collection uses their independently verified underlying season and
fixture feeds, not browser scraping or the minute-rounded display times.

On 24 October 2026, sampled English, Bundesliga and Euro schedules had 220-second
kickoff spacing and a 340-second last-round-to-next-season-first-round gap.
Bundesliga 3149079 round 30 scheduled at 01:28:11 UTC, followed by 3149108 round 1
at 01:33:51 UTC. Countdown expiry was observed roughly ten seconds before a
scheduled kickoff. These are observations, not permanent provider contracts.
Do not extrapolate future dates using fixed 220/340-second intervals.

## Worker behavior

Use the same commands in `continuous-collection.md` to start the collectors.
`provider:collect` and `provider:watch --once` both respect the persisted next due
time; `provider:probe` remains an independent, read-only diagnostic.

Each due cycle discovers the marked current season and reads its fixtures.
It captures odds for the earliest two distinct upcoming rounds. When the horizon
crosses a season boundary, remaining slots are filled from the earliest advertised
future season, ordered by its supplied start timestamp. A round is marked captured
only after eight prices passed pre-match normalization. Successful rounds are not
re-fetched just because another result check runs. These are capture-time prices,
not a promise of final prices or bookmaker acceptance.

The next collection is scheduled from actual fixture timestamps: advance the
two-round horizon after kickoff, check unconfirmed results initially at kickoff
plus 200 seconds, and rediscover at the supplied season end. The 200-second delay
is a configurable conservative starting policy, **not verified exact duration**.
Still-missing results retry after 30 seconds; unknown odds/incomplete fixtures
retry after 45 seconds; transport/storage failures retain exponential backoff.
Unknown schedules use a bounded fallback. A successful HTTP response can still
contain partial results, so retries cannot depend on transport failure alone.

At rollover, the last observed season is queued for reconciliation unless its
results were complete. At most one due old season is checked per cycle. Repeated
missing/inaccessible results back off up to one hour without stopping current
collection. Progress survives restarts in `collector_states`; every scoped capture
also advances a separate `season_checkpoints` pointer. Preview and repair captures
never replace `provider_checkpoints`, which remains current-season-only.

This reconciles observed seasons. Entirely missed seasons during downtime are not
silently bridged or automatically declared consecutive; continuity verification
remains necessary before strategies can run.

## Completeness and availability

A valid complete fixture schedule requires 240 distinct matches, 30 rounds of
eight, 16 different participating teams per round, and one fixture per directed
home/away pairing. Result completeness requires all those matches settled.
Missing schedule times are tracked separately; actual kickoff remains unknown.
These checks do not establish reliable pre-match odds coverage or official
champion/tiebreak correctness. `strategy_ready` and general `data_complete` stay
false; no signals, stakes or bankroll execution are enabled.

Odds failures do not discard successfully normalized fixtures/results. Empty,
partial or malformed prices are labelled `availability_unknown`, not an outage
or confirmed betting closure. Their public feed response (when received) is saved
privately in the `kiotrad_provider_evidence` volume, alongside normalized historical
context in MongoDB. Credentials, browser cookies and exception messages are excluded.
The volume is mounted for API and collectors and is not publicly served or committed.
Evidence retention/export policies are a later operational task.

The overview exposes result/fixture counts, unresolved old seasons, the upcoming
round horizon and the next scheduled check. A capture older than 120 seconds can
be labelled awaiting scheduled update until the next due time; this is not a
worker heartbeat or proof of live matches. Display dates/times use Africa/Lagos.
