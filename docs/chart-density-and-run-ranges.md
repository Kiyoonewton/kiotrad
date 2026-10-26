# Chart density and per-run cash ranges

Candles support 1-hour, 4-hour, 1-day and 7-day buckets anchored in Lagos time. The visible-node slider shows fewer or more of the latest recorded events/buckets; it does not synthesize prices or observations. Keyboard event inspection continues to work within the visible window. Line openings retain the value immediately before the visible window.

Bars now encode the reference value at each settlement event: configured starting capital plus cumulative completed independent-run net, not the isolated event profit. Positive/negative event changes control the green/red color. This remains a reference path; it does not model simultaneous capital reservations or assert a funded shared account balance.

Replace aggregate stake turnover and gross-payout totals with low/high total stake-out and gross payout per completed run. Stake-out is the sum of stakes across that run's attempts. For a completed run, net = gross payout − total stake-out, hence stake-out = payout − net (including a negative net on losses). Zero payouts on losing runs are included.
