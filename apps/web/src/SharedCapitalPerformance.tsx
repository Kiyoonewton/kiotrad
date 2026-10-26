import { naira } from "./api";
import { ResearchChart } from "./ResearchChart";
import type { SharedSimulation } from "./sharedSimulation";
import type { ResearchRun } from "./researchChartData";

export function SharedCapitalPerformance({ data }: { data: SharedSimulation }) {
  if (data.status !== "available")
    return (
      <section className="message-panel">
        <h1>Shared-capital simulation</h1>
        <p>
          Calculation unavailable. Existing independent results are not
          substituted for a shared balance.
        </p>
      </section>
    );
  const accepted = data.runs.filter(
    (r) => r.estimate && r.status !== "skipped_simultaneous_entry",
  );
  const closed = accepted.filter((r) => r.net_minor != null && r.released_at);
  const skipped = data.runs.filter((r) => r.status.startsWith("skipped_"));
  const reasons = new Map<string, number>();
  for (const r of data.runs.filter(
    (r) => !r.status.startsWith("skipped_") && r.net_minor == null,
  ))
    reasons.set(r.status, (reasons.get(r.status) ?? 0) + 1);
  for (const r of data.excluded ?? [])
    reasons.set(r.reason, (reasons.get(r.reason) ?? 0) + 1);
  const unresolved = [...reasons.values()].reduce((a, b) => a + b, 0);
  const coverage =
    closed.length + unresolved
      ? (100 * closed.length) / (closed.length + unresolved)
      : 0;
  const runs: ResearchRun[] = closed.map((r) => ({
    competition: r.competition!,
    season_id: r.season_id!,
    strategy_id: r.strategy_id!,
    team: r.team!,
    status: r.status,
    net_minor: r.net_minor!,
    total_staked_minor: r.total_staked_minor ?? 0,
    gross_payout_minor: r.gross_payout_minor ?? 0,
    event_at: r.released_at!,
    started_at: r.entry_at,
    attempts: r.attempts?.length,
  }));
  const net = data.balance_minor - data.starting_capital_minor;
  return (
    <>
      <section className="balance-header">
        <div>
          <p className="eyebrow">One active run · all leagues and strategies</p>
          <h1>Shared-capital simulation.</h1>
          <p>
            Proportional profit targets · frozen per run · ×2 estimated
            allowance
          </p>
        </div>
        <div className="balance-value">
          <p>Settled simulation balance</p>
          <strong>{naira(data.balance_minor)}</strong>
          <span>
            Initial {naira(data.starting_capital_minor)} · next doubling
            milestone{" "}
            {naira(
              data.starting_capital_minor *
                2 **
                  Math.max(
                    0,
                    Math.floor(
                      Math.log2(
                        Math.max(
                          1,
                          data.balance_minor / data.starting_capital_minor,
                        ),
                      ),
                    ),
                  ) *
                2,
            )}
          </span>
          <span className={net < 0 ? "negative" : "positive"}>
            Net {naira(net)}
          </span>
        </div>
      </section>
      <div className="research-metrics">
        <div>
          <small>Completed accepted runs</small>
          <strong>{closed.length}</strong>
        </div>
        <div>
          <small>Skipped clashes</small>
          <strong>{skipped.length}</strong>
        </div>
        <div>
          <small>Reserved allowance</small>
          <strong>{naira(data.reserved_allowance_minor)}</strong>
        </div>
        <div>
          <small>Unresolved candidates</small>
          <strong>{unresolved}</strong>
        </div>
      </div>
      <div className="shared-run-ranges">
        {[
          [
            "Run stake-out · low / high",
            closed.map((r) => r.total_staked_minor ?? 0),
          ],
          [
            "Run payout · low / high",
            closed.map((r) => r.gross_payout_minor ?? 0),
          ],
        ].map(([label, values]) => (
          <div key={String(label)}>
            <small>{String(label)}</small>
            <strong>
              {closed.length
                ? `${naira(Math.min(...(values as number[])))} / ${naira(Math.max(...(values as number[])))}`
                : "—"}
            </strong>
          </div>
        ))}
      </div>
      <div className="performance-grid">
        <ResearchChart
          runs={runs}
          initial={data.starting_capital_minor}
          shared
        />
        <aside className="goal-panel coverage-panel">
          <p className="eyebrow">Calculation completeness</p>
          <h2>{coverage.toFixed(1)}%</h2>
          <div className="coverage-track">
            <i style={{ width: `${coverage}%` }} />
          </div>
          <p>
            {closed.length} completed · {unresolved} unresolved ·{" "}
            {skipped.length} clashes excluded
          </p>
          <dl className="coverage-details">
            {[...reasons].map(([reason, count]) => (
              <div key={reason}>
                <dt>{reason.replaceAll("_", " ")}</dt>
                <dd>{count}</dd>
              </div>
            ))}
          </dl>
          <p className="subtle">
            Capital release uses recorded result confirmation. Late archive
            evidence can leave historical overlap timing unresolved.
          </p>
          <p>
            Pending stakes {naira(data.active_staked_minor)}. Reserved capital
            is not freely available.
          </p>
        </aside>
      </div>
      <details className="quiet-panel">
        <summary>Accepted runs and skipped opportunities</summary>
        <div className="shared-run-table">
          <table>
            <thead>
              <tr>
                <th>Run</th>
                <th>Status</th>
                <th>Profit / attempt</th>
                <th>Allowance</th>
                <th>Staked</th>
                <th>Net</th>
              </tr>
            </thead>
            <tbody>
              {data.runs.map((r) => (
                <tr key={r.id}>
                  <td>{r.team ?? r.id}</td>
                  <td>{r.status.replaceAll("_", " ")}</td>
                  <td>
                    {r.estimate
                      ? naira(r.estimate.plan.profit_per_attempt_minor)
                      : "—"}
                  </td>
                  <td>
                    {r.estimate ? naira(r.estimate.allowance_minor) : "—"}
                  </td>
                  <td>
                    {r.total_staked_minor != null
                      ? naira(r.total_staked_minor)
                      : "—"}
                  </td>
                  <td>{r.net_minor != null ? naira(r.net_minor) : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </>
  );
}
