# Stake and profit calculations on strategy observations

Qualified current-season observations include a read-only independent run replay.
No paper-worker activation or real funding is required to calculate this view.
It appears under the observation on Overview and Strategies, with each attempt's
fixture, day, recorded odds, stake, payout and result, plus total cost and net.

Default per-run capital is NGN 7,000, adjustable with
`SIMULATION_CAPITAL_NAIRA` (100 to 10,000,000). It is explicitly separate from the
shared paper portfolio. Calculations do not reserve funds, change its chart,
record retrospective paper decisions or send Slack execution notifications.
Combining overlapping independent runs into a shared-bankroll history is not
implemented by this view. Historical date selection and portfolio replay remain
separate milestones.

The replay uses existing strategy qualification and order: Next Win starts the
match after the champion's first win on day four or later; Next Season starts
on day one of its qualified following season. Maximum four attempts, stop at
first win. Each whole-naira stake recovers earlier losses plus attempt × NGN 100,
minimum NGN 100. Gross payout is separate from profit; pending payouts are not
counted as realized gains. All four losses and capital limits remain visible.

Only pre-match-verified prices stored in a pre-start fixture capture are eligible.
Decision time is the later of qualification/result knowledge and quote receipt;
price freshness is at most 300 seconds. Later results cannot choose earlier
prices or enable an entry after kickoff. Same-time conflicting price snapshots
are rejected. Fixture identity/schedule changes remain unsettled for review.
Missing eligible odds stop pricing at that attempt rather than fabricating a
stake or skipping to a later winning fixture. Actual HTTP response/observation
times are distinct from scheduled event time.

Policy version: `as-observed-win-recovery-1.0.0`. This is a deterministic replay
of stored observations, not proof the bookmaker would accept a particular stake
or that a strategy is profitable outside these observations. Source capture data
and existing portfolio decisions stay immutable. The API retains only the tracked
team's fixtures/prices in replay memory; the durable archive remains unchanged.
