# Champion observation monitoring

The overview includes read-only monitoring for Champion — Next Win and Champion — Next Season, evaluator version `0.3.0-observation-day5`. The catalog enables observation only; no signals, stakes, profits or paper executions are created by this milestone.

Next Win finds the prior champion’s first win, requiring a settled result for every preceding day. Day five or later qualifies; a first win on day four does not. Monitor that team’s next four fixtures, ending at the first observed win or season end. Draws count as non-wins. There is no ten-game trigger cutoff: the complete season is examined; a season without any win creates no trigger.

Next Season requires two observed season transitions. A first win on day five or later in the preceding transition qualifies following the preceding season’s own champion from day one, for four fixtures. Team IDs, not names, determine results.

Collection writes immutable `provider_season_transitions` when the current-season flag changes. A change seen after more than ten minutes without a successful cycle is `unconfirmed_gap` and cannot qualify. This conservative operational guard is not a claim that provider season IDs are consecutive. Bootstrap archive neighbours never create transitions. New installations must observe rollovers before monitoring can qualify.

Only captures received by evaluation time are read. First received settled results and verified champion confirmation times are retained in the projection. Champion confirmation must predate the trigger season’s opening fixture; trigger, observed rollover and all prior attempt evidence must predate the next fixture. A rollover only observed after day one blocks a reconstructed Next Season entry. Late backfills display blocked states instead of hypothetical live opportunities.

Monitoring is a reconstruction of observed results, not a record of bets. It does not yet assess price freshness, bookmaker acceptance, capital, overlaps, or stake feasibility. A `watching_fixture` status is not permission to trade. Next milestone: immutable decisions, odds eligibility and a shared-capital paper ledger. Long-term projections should be materialized/indexed before capture volume grows; this implementation reads only the current and up to two linked seasons.

Qualification changed from day 4+ to day 5+ in this version. The trading window remains four attempts. Existing recorded journal decisions retain their original version and are not rewritten; the current research view recalculates history under the new rule.
