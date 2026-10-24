# Official final standings and champions

The public provider endpoint `stats_season_tables/{season}` was inspected on
24 October 2026 for English 3149067, Bundesliga 3149079 and Euro 3149053.
Each returned a 16-row overall table, official `pos` ranking, played/W/D/L,
goals for/against, goal difference, points and tiebreak-rule text.

The supplied priority is points, goal difference, goals scored, head-to-head
goals/away goals, then a provider-specific MD5 fallback. KioTrad preserves the
official positions and rule text. It does not invent an alphabetical fallback
or attempt to reproduce unspecified hash formatting.

Historical table responses can contain nested `tournament.seasonid` and
`currentseasonid` fields pointing to a newer season. Validate the response's
root season/league, the selected overall table's own `seasonid`, and the stable
tournament UID. `maxrounds` was observed as 3 even on a completed 30-round table;
it is not used as proof of completion.

## Confirmation gate

Only attempt final-table verification after a structurally complete season has
240 settled fixtures. Require 16 unique team UIDs and unique official positions
1–16. Check W+D+L=played, points=3W+D, GF−GA=GD, all 30 played, and the basic
points/GD/GF ordering. Independently calculate each team's totals from fixture
scores and require exact agreement. Equal-stat ties retain official `pos` order.
Incomplete, mismatched or inaccessible evidence yields no confirmed champion.

The normalized table, source, rule text, request/receive times and confirmed
champion are stored inside an immutable provider capture. Later observations
remain separate captures. Verification time is when the table was received,
not the historical season end or an invented earlier knowledge time.

## Worker and bootstrap

On initial startup, queue only the closest older season advertised by the
same-league archive's supplied start times. This bootstraps the previous champion
without connecting to `my_trade` or crawling the entire archive. The completed
bootstrap is not repeated. Missing fixture results retain existing reconciliation
backoff; complete results with unverified standings retry after 30 seconds.
An old season leaves the queue only after both results and final table verify.

The latest champion display is ordered by the season's first fixture timestamp,
not numeric season IDs or the order requests finish. Older repairs cannot replace
a newer verified champion. Provider corrections can produce later evidence when
re-read; finalized historical tables are not continuously polled forever.

Archive adjacency is not yet a strategy-eligibility guarantee. Entirely missed
seasons, champion evidence available only after a trigger, and first-win-window
rules still require the chronological strategy evaluator. Paper trading stays off.

## Local inspection

After rebuilding, inspect one season independently:

```bash
docker compose --env-file engine/.env -f engine/compose.yaml exec api \
  php artisan provider:standings virtual-bundesliga 3149079
```

Use `virtual-football` or `virtual-euro` and the matching provider season ID for
other leagues. This persists a season-only reconciliation capture and prints the
full verified table and champion/status. It never replaces the current-season
pointer or creates strategy signals/trades. An incomplete season or unverified
table exits nonzero with an unconfirmed champion.

Normal workers update the overview with the latest verified champion, including
its season ID and confirmation time in the API. Strategies remain disabled.
