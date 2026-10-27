# Interactive research simulator

Open **Simulator** in the navigation. Capital, qualification, attempts, allowance and strategy changes recalculate automatically after a 400ms typing debounce. The top-bar league selector defines the portfolio scope. Preferences are saved in this browser only; the monitored default benchmark, collector settings, paper journal and Slack signals are not changed.

Supported controls:

| Setting | Range |
| --- | --- |
| Initial capital | ₦100–₦10,000,000, whole naira |
| Qualifying first-win day | 1–30 inclusive |
| Maximum trading attempts | 1–10, ending at season day 30 |
| Estimated allowance | ×1 or ×2 |
| Strategy | Both, Champion — Next Win, or Champion — Next Season |

Qualification and attempt count are independent. Next Win begins the fixture after the qualifying first win; Next Season follows the next season’s own previous champion from day 1. Every scenario retains one active run, original overlap selection, real saved prices, minimum NGN100 stake, upward whole-naira rounding, frozen per-run targets and the +201s settlement model with +180/+220 sensitivity comparisons. Missing evidence remains unresolved. A window shorter than the requested attempt count because the season ends is reported as `season_end`.

## Processing

1. Existing collectors save permanent raw evidence and normalized MongoDB views.
2. `dashboard:watch` prepares a reusable Redis frame after refreshing the default dashboard. Preparation uses verified season links and identities, records first-win days without filtering at the default threshold, and joins up to ten subsequent fixtures and their prices/minima for both strategies.
3. `GET /api/v1/research/scenario` validates settings, filters/slices prepared candidates and replays money/event allocation. It performs no MongoDB reads, raw reconstruction, collection or journal writes. A missing frame returns HTTP202, not a fabricated zero result; the browser retries.
4. Result cache keys include the local/production database namespace, prepared data revision, all settings, and calculation policy versions. Identical settings reuse the result for up to 24h. Source metadata changes invalidate the prepared frame and scenario keys. Prepared frames have a seven-day TTL.

Cold preparation can take time. Once the frame exists, settings changes require cached evidence and arithmetic only. The screen displays the evidence cut-off in Lagos time and whether a result was cached. Late responses from previous settings are discarded; invalid inputs do not submit a calculation. No completion time or return is promised.

The arithmetic engine currently supports sizing entries with up to ₦10,000,000 available capital. If profits take capital above that limit, subsequent entries are explicitly excluded as `calculation_range_limit`; the simulator shows a limitation banner rather than inventing stakes or overflowing integer calculations.

## Installation

After merging, rebuild the API, web and dashboard worker together. No raw resync is required. On first use, wait for `workbench: prepared`; further unchanged refreshes show `workbench: unchanged`. The background worker keeps the previous prepared frame if source preparation fails. Simulator settings never replace environment variables or production strategy definitions.
