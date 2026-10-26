# Visual research dashboard

The overview includes every stored season available to the history service, rather than the previous seven-day event window. Its aggregate cache fingerprints checkpoints, catalog links, transitions, blockage evidence, strategy versions and configured capital. An unchanged revision reuses the summary. Source changes refresh the aggregate; existing per-season simulation caches reuse unchanged seasons and recompute repaired/current source versions. Redis loss requires reconstruction, not data loss. Older requested season caches still retain the existing one-day policy; current/recent calculations use seven days.

Line, candle and bar views use integer-kobo results. The line/candles show configured starting capital plus cumulative completed independent-run net as a reference path, not a funded shared bankroll. Bars show net per settlement event. Simultaneous events are combined before computing candle extremes/drawdown. Candle buckets use Lagos calendar dates and aggregate reference-path open/high/low/close; they are not market-price candles. Missing prices remain incomplete and are excluded from completed net.

The header displays actual configured starting capital and separately shows net. To use NGN 14,000, set SIMULATION_CAPITAL_NAIRA=14000 in the environment and recreate API/dashboard-worker. This changes simulation funding and invalidates cached calculations; the UI never substitutes the target for starting capital or invents a configured value.

League cards use collection progress bars and collapsible evidence details. Strategy scorecards show outcome tiles and totals. The league event map uses scheduled final-attempt times; it does not claim exact exposure-duration overlap without actual settlement/end times.
