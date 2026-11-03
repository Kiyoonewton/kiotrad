import { naira } from "./api";
import type { SharedRun } from "./sharedSimulation";

import { settledStrategyNet } from "./strategyNet";

export function StrategyTotals({ runs }: { runs: SharedRun[] }) {
  const accepted = runs.filter(
    (run) =>
      run.estimate &&
      run.funded !== false &&
      !run.status.startsWith("skipped_"),
  );
  const settled = accepted.filter(
    (run) => run.net_minor != null && run.released_at,
  );
  return (
    <div className="research-metrics" aria-label="Strategy totals">
      <div>
        <small>Completed</small>
        <strong>{settled.length}</strong>
      </div>
      <div>
        <small>Placed stakes</small>
        <strong>
          {naira(
            accepted.reduce(
              (sum, run) => sum + (run.total_staked_minor ?? 0),
              0,
            ),
          )}
        </strong>
      </div>
      <div>
        <small>Payout</small>
        <strong>
          {naira(
            accepted.reduce(
              (sum, run) => sum + (run.gross_payout_minor ?? 0),
              0,
            ),
          )}
        </strong>
      </div>
      <div>
        <small>Settled net</small>
        <strong>{naira(settledStrategyNet(runs))}</strong>
      </div>
    </div>
  );
}
