# Live feed discovery

Verified against the public provider statistics application on 23 October 2026 UTC.
These are observed feed behaviours, not a provider SLA or guarantee of future access.

## Sources

The public statistics application's resource configuration and feed client expose:

- Statistics base: `https://vgls-vs001.akamaized.net/vfl/feeds/?/bet9javirtuals/en/Europe:Berlin/gismo/`
- `uniquetournament_seasons/s-{seed}`: season list, tournament IDs and explicit `current` flag.
- `stats_season_meta/{season}`: metadata and a current-season pointer; observed max-age 3600 seconds.
- `stats_season_fixtures2/{season}`: dated fixtures, match/team IDs, rounds and full-time scores.
- `stats_match_get/{numeric_match_id}`: individual match detail.
- `stats_season_tables/{season}`: referenced by the website's standings component; full parsing still to verify.
- Odds base: `https://vgls.betradar.com/vfl/feeds/?/bet9ja/en/Europe:Berlin/gismo/`
- `vfc_stats_round_odds2/vf:season:{season}/{round}`: market/outcome prices and settlement flags.

Discovery uses the season list's single explicit `current: true`, not the highest
numeric season ID or newest archive row (which can be a future season). The list
was observed with a 40-second max-age, substantially shorter than season metadata.
Bootstrap seeds identify a league, not a manually entered current season. They
can be replaced through the three `PROVIDER_*_SEED` environment variables if the
provider eventually retires the seed. Ambiguous or mismatched responses fail closed.

The website's inspected English fixtures page was:
https://st-cdn001.akamaized.net/bet9javirtuals/en/1/season/3149067/fixtures

## Event time versus response time

Fixture `time.uts` is Unix seconds for scheduled event start. The same event had
the same epoch under America:Chicago and Europe:Berlin feeds, while formatted
date/time and timezone labels differed. Convert the epoch once to UTC; do not
apply `tzoffset` again. The season's lobby/start time can precede the first match.

Odds document `timestamp` is response-generation milliseconds. Statistics `_dob`
is feed-generation seconds. Neither is kickoff. Capture local request and receive
times independently. No explicit actual-start field has been verified; it stays null.

### Observed evidence

- English season 3149067, event 1412131282: scheduled 23:00:52 UTC. Odds received
  23:00:19 UTC had active 1X2 outcomes with result -1. By 23:03:45 UTC the fixture
  contained full-time 0–2. This confirms a pre-schedule price capture and later result.
- Euro season 3149053, event 1412129684: scheduled 23:00:34 UTC; the 23:00:08
  fixture capture had no full-time result. Later observation contained full-time 2–0.
- Bundesliga season 3149052, event 1412129452: scheduled 23:03:11 UTC; an earlier
  fixture capture had no full-time result. Subsequent match detail contained 2–1.
- Independent PHP probes captured 240 scheduled fixtures and eight pre-match 1X2
  prices each for English, Bundesliga and Euro. Local diagnostic files retain the
  request/receive times and original documents privately; raw payloads are not committed.

This does not prove exact actual kickoff or an explicit live status. Pending data
after scheduled start is labelled unconfirmed rather than invented live coverage.
Cross-league start times must be measured, not assumed to have a fixed offset.

## Identity and results

Statistics match `_id: 1412131282` joins odds `id: vf:match:1412131282`.
Statistics team `uid` joins odds `sr:competitor:{uid}`. Check both teams, season,
round and tournament before accepting the join. Do not join by names alone.

1X2 market ID is 1, with outcome IDs 1=H, 2=D, 3=A. Preserve prices as decimal
strings. A pre-match label requires the response to arrive before scheduled start,
all outcomes to be active and unsettled, and no full-time result in the fixture.
This proves observed provider availability, not bookmaker ticket acceptance.

Observed completed fixtures still have `result.period: nt`. A 0–0 draw can have
`winner: null`. Require explicit nonnegative integer `periods.ft.home/away` evidence
for settled scores; never classify by `period`, winner truthiness or clock alone.

The UI states tiebreaks: goal difference, goals scored, H2H goals/away goals,
then MD5 (TeamId, Goals Scored). Use verified official standings rather than an
alphabetical research fallback; table parsing and correction semantics remain work.

## One-shot diagnostic

After starting/rebuilding the stack:

```bash
sh engine/start.sh
docker compose --env-file engine/.env -f engine/compose.yaml run --rm --no-deps api \
  php artisan provider:probe
```

Optional: `php artisan provider:probe virtual-euro --round=22` to inspect a specific
league/round. A historical round is retained as historical; it is not pre-match data.

Snapshots are private JSON files in `storage/app/private/provider-probes`. Docker
persists them in `kiotrad_provider_diagnostics`, visible to the running API container:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec api \
  ls storage/app/private/provider-probes
```

The command does not write MongoDB, enable a feed, evaluate strategies or place
paper trades. A failed league exits nonzero while allowing the other league probes
to finish. Local transport initially hit proxy CONNECT timeouts; increasing connect
timeout to 10 seconds allowed successful captures. Persistent polling/backoff,
restart checkpoints, fixture revisions, full lifecycle observation and freshness
monitoring belong to the next collection milestone. Respect provider cache TTLs;
this diagnostic is not a high-frequency polling loop.
