import { naira } from "./api";
import type { CarryPlan } from "./sharedSimulation";

export function CarryPlanTable({
  plan,
  profit,
}: {
  plan: CarryPlan;
  profit: number;
}) {
  return (
    <div className="paper-table-scroll">
      <table className="attempt-table">
        <thead>
          <tr>
            <th>Attempt / day</th>
            <th>Estimated odds</th>
            <th>Actual odds</th>
            <th>Previous potential payout</th>
            <th>Target</th>
            <th>Stake</th>
            <th>Potential payout</th>
            <th>Total spent</th>
            <th>Net if won</th>
          </tr>
        </thead>
        <tbody>
          {plan.attempts.map((row) => (
            <tr key={row.attempt}>
              <td>
                {row.attempt} / D{row.matchday}
              </td>
              <td>{(row.odds_scaled / 10000).toFixed(2)}</td>
              <td>{(row.actual_odds_scaled / 10000).toFixed(2)}</td>
              <td>{naira(row.previous_potential_payout_minor)}</td>
              <td>{naira(profit)}</td>
              <td>{naira(row.stake_minor)}</td>
              <td>{naira(row.potential_payout_minor)}</td>
              <td>{naira(row.total_staked_minor)}</td>
              <td>{naira(row.net_if_won_minor)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
