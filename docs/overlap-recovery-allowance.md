# Overlap map and recovery allowance

This version changes paper research only. It does not execute bets or deploy to production.

## Allowance after a losing run

Normal sizing requires twice the estimated run cost to fit available capital. When an accepted run closes with a negative net, record capital immediately before that loss as the recovery threshold. Subsequent runs use **one times estimated run cost**, until settled shared capital reaches or exceeds that threshold. Then restore the normal two-times allowance.

Profit targets continue sizing proportionally to available capital and the selected allowance factor. They are frozen only within an individual accepted run. They are not held fixed across recovery runs. The doubling milestone rule is unchanged. A further loss during recovery keeps the original threshold rather than moving it down.

Actual stakes must still fit the frozen allowance and available capital. With the one-times rule, estimated cost can use essentially the full remaining capital; there is no second-copy reserve. Actual prices below historical minima can still trigger an allowance stop. Insufficient capital remains a budget skip.

## Across the leagues

The map now reads accepted and clashing shared-run entries, rather than independently priced runs. Selected entries and skipped entries occupy slightly offset tracks within each league/strategy lane, so overlapping spans remain distinguishable.

- Green: selected run with nonnegative realized net.
- Red: selected run with a realized loss.
- Blue: skipped entry with a win observed in its original fixture window.
- Amber: skipped entry whose available window completed without a win.
- Grey: unresolved result.

A skipped outcome is an observation, never a funded simulation result: no stake, payout or profit is added. Entry, recorded end and the ID of the blocking active run are inspectable. Outcome inspection is retrospective and cannot influence which run was originally selected. Missing or future result confirmation stays unresolved.

Recorded confirmation times are conservative for late archive evidence. An unresolved map span ends at the last scheduled fixture, explicitly labelled; it does not prove an actual end time. Existing zoom, fixed labels and horizontal panning are preserved.

Rebuild API, web and dashboard-worker together after merging. The new replay and budget policy versions invalidate cached calculations; source data needs no reset. Wait for `dashboard: refreshed` before reviewing new results. Re-run the historical benchmark with the changed recovery policy before treating it as comparable with older outputs.
