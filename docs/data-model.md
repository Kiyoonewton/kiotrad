# Reusable data contract (planned)

This is a design contract, not a claim that ingestion or indexes exist yet.
The foundation only serves configuration and dependency readiness.

## Provider data

| Collection | Identity / indexes | Contents |
| --- | --- | --- |
| competitions | unique provider + competition_id | Names, format and provider tournament mapping |
| seasons | unique provider + competition_id + season_id | Lifecycle, discovery order, verified predecessor/successor, completeness |
| teams | unique provider + team_id | Stable identity, aliases and display names; names alone are not identity |
| matches | unique provider + match_id | Season, matchday, team IDs, venue, status, scores and result |
| odds_snapshots | match + market + outcome + captured_at; dedup by source observation | Decimal odds as strings, market identity, suspension and provider timestamp |
| standings_snapshots | season + completed_matchday + revision | Points, official rank/tiebreak availability, source match IDs |
| provider_responses | retrieval ID; payload hash for duplicate detection | Endpoint, request/response times, status, payload, parser version |

Provider responses must exclude credentials, cookies and secret query parameters.
Raw payload retention should be configurable; preserve the observation provenance
needed to reproduce a signal even if raw payloads are later expired.

## Time fields

Store UTC BSON dates; serialize ISO 8601 with an explicit offset. Display Africa/Lagos.

- `scheduled_start_at`: provider schedule, nullable until verified.
- `actual_start_at`: provider explicitly reports actual start, otherwise null.
- `first_seen_at`: first local observation, never overwritten by a later fetch.
- `started_seen_at`: first local observation of a live status, not exact kickoff.
- `settled_seen_at`: first local observation of a settled result, not exact finish.
- `captured_at`: local capture time for each odds snapshot.
- `provider_timestamp`: original provider value with documented semantics.
- `time_source` and `time_confidence`: provenance and whether verified or estimated.

Keep status/result corrections as revisions. Fetching historical results today
does not make their odds available to a simulated decision yesterday.
Sequence IDs are not timestamps. Incomplete/missing seasons must not be bridged
without verified continuity. Never assign invented event times to old records.

## Strategy data

- `strategy_versions`: immutable rule parameters, evaluator version and description.
- `signals`: version, eligible fixture, observed trigger time, evidence IDs and expiry.
- `runs`: signal, acceptance decision, independent strategy result vs portfolio result.
- `attempts`: unique run + attempt; actual captured odds, stake, settlement and status.
- `bankroll_transactions`: immutable integer-kobo ledger, idempotent event key.

Keep shared provider records strategy-agnostic. Snapshot or reference the exact
observations available at the decision cutoff. Corrections must not silently
rewrite an already recorded decision. Strategy changes create a new version.

Reserve capital atomically. Accept only one active run for the initial portfolio
policy. Deterministic chronological signal priority must not use future outcomes.
Keep skipped conflicts and insufficient-capital decisions for comparison.

Replay and live modes use the same evaluator with different event sources.
Backtests on odds of unverified pre-match availability are exploratory and labelled.
