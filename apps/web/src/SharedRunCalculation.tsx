import { CarryPlanTable } from "./CarryPlanTable";
import { naira } from "./api";
import type { SharedRun } from "./sharedSimulation";

export function SharedRunCalculation({ run }: { run?: SharedRun }) {
  if (!run)
    return (
      <p className="subtle">
        No accepted shared-capital run for this opportunity.
      </p>
    );
  if (run.funded === false)
    return (
      <div className="run-calculation">
        <strong>
          Unfunded entry · {run.entry_issue?.replaceAll("_", " ")}
        </strong>
        <p>No stake placed · no capital reserved · profit unresolved</p>
      </div>
    );
  return (
    <div className="run-calculation">
      <strong>{run.status.replaceAll("_", " ")}</strong>
      {run.estimate && (
        <p>
          Frozen profit per attempt{" "}
          <b>{naira(run.estimate.plan.profit_per_attempt_minor)}</b> · reserved
          allowance ×{run.estimate.allowance_multiplier ?? 1}:{" "}
          {naira(run.estimate.allowance_minor)}
        </p>
      )}
      {run.estimate?.baseline && (
        <details className="run-details">
          <summary>Baseline &amp; full-window plan</summary>
          <p>
            Retrospective sizing · full-window actual odds required. Potential
            payouts below assume a win on that row.
          </p>
          <p>
            ₦100 baseline cost: {naira(run.estimate.baseline.total_minor)} ·
            selected profit target:{" "}
            {naira(run.estimate.plan.profit_per_attempt_minor)}
          </p>
          <h4>₦100 baseline</h4>
          <CarryPlanTable plan={run.estimate.baseline} profit={10000} />
          {run.estimate.plan.attempts && (
            <>
              <h4>Capital-sized plan</h4>
              <CarryPlanTable
                plan={{
                  total_minor: run.estimate.plan.total_minor,
                  attempts: run.estimate.plan.attempts,
                }}
                profit={run.estimate.plan.profit_per_attempt_minor}
              />
            </>
          )}
        </details>
      )}
      <dl className="run-totals">
        <div>
          <dt>Staked</dt>
          <dd>
            {run.total_staked_minor != null
              ? naira(run.total_staked_minor)
              : "—"}
          </dd>
        </div>
        <div>
          <dt>Payout</dt>
          <dd>
            {run.gross_payout_minor != null
              ? naira(run.gross_payout_minor)
              : "—"}
          </dd>
        </div>
        <div>
          <dt>Net</dt>
          <dd>{run.net_minor != null ? naira(run.net_minor) : "unresolved"}</dd>
        </div>
      </dl>
      {!!run.attempts?.length && (
        <details className="run-details">
          <summary>Calculation details</summary>
          <p>
            Shared-capital simulation · capital at entry{" "}
            {run.capital_at_entry_minor != null
              ? naira(run.capital_at_entry_minor)
              : "—"}
            {run.sizing_capital_at_entry_minor != null && (
              <> · sizing capital {naira(run.sizing_capital_at_entry_minor)}</>
            )}
            . Target stays fixed during this run.
          </p>
          <div className="paper-table-scroll">
            <table className="attempt-table">
              <thead>
                <tr>
                  <th>Day</th>
                  <th>Fixture</th>
                  <th>Odds</th>
                  <th>Stake</th>
                  <th>Payout</th>
                </tr>
              </thead>
              <tbody>
                {run.attempts.map((a) => (
                  <tr key={a.attempt}>
                    <td>{a.matchday}</td>
                    <td>
                      {a.home_team} vs {a.away_team}
                    </td>
                    <td>
                      {a.actual_odds_scaled != null
                        ? (a.actual_odds_scaled / 10000).toFixed(2)
                        : "—"}
                    </td>
                    <td>{naira(a.stake_minor)}</td>
                    <td>
                      {a.payout_minor != null ? naira(a.payout_minor) : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      )}
      {run.skip_reason && <p>{run.skip_reason.replaceAll("_", " ")}</p>}
    </div>
  );
}
