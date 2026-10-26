import { useState } from "react";
import { naira, type Overview } from "./api";
import { ResearchChart } from "./ResearchChart";
import { chartData } from "./researchChartData";

export function ResearchPerformance({
  data,
  league,
}: {
  data: NonNullable<Overview["research_simulation"]>;
  league: string;
}) {
  const [strategy, setStrategy] = useState("all");
  if (data.status !== "available")
    return (
      <section className="message-panel">
        <h1>Research simulation</h1>
        <p>
          Simulation summary is unavailable. Stored data has not been removed.
        </p>
      </section>
    );
  const runs = data.runs.filter(
    (r) =>
      (league === "all" || r.competition === league) &&
      (strategy === "all" || r.strategy_id === strategy),
  );
  const completed = runs.filter((r) => r.net_minor !== null);
  const net = completed.reduce((sum, r) => sum + r.net_minor!, 0);
  const wins = completed.filter((r) => r.status === "win").length;
  const losses = completed.length - wins;
  const initial = data.starting_capital_per_run_minor ?? null;
  const staked = completed.reduce((sum, r) => sum + r.total_staked_minor, 0);
  const payouts = completed.reduce((sum, r) => sum + r.gross_payout_minor, 0);
  const { drawdown } = chartData(runs, initial ?? 0);
  const rate = completed.length ? (wins / completed.length) * 100 : 0;
  const strategies = [...new Set(data.runs.map((r) => r.strategy_id))];
  return (
    <>
      <section className="balance-header" aria-labelledby="research-title">
        <div>
          <p className="eyebrow">Evaluate strategies over time</p>
          <h1 id="research-title">Research simulation.</h1>
          <label className="strategy-filter">
            Strategy{" "}
            <select
              value={strategy}
              onChange={(e) => setStrategy(e.target.value)}
            >
              <option value="all">All strategies</option>
              {strategies.map((s) => (
                <option key={s} value={s}>
                  {s.replaceAll("-", " ")}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div className="balance-value">
          <p>Configured starting capital per run</p>
          <strong>{initial !== null ? naira(initial) : "₦ —"}</strong>
          <span>
            Independent-run net{" "}
            <b className={net < 0 ? "negative" : "positive"}>
              {completed.length ? naira(net) : "—"}
            </b>
          </span>
          <span>All stored seasons · event order</span>
        </div>
      </section>
      <div className="research-metrics">
        <div>
          <small>Completed runs</small>
          <strong>{completed.length}</strong>
        </div>
        <div>
          <small>Net profit / loss</small>
          <strong className={net < 0 ? "negative" : "positive"}>
            {completed.length ? naira(net) : "—"}
          </strong>
        </div>
        <div>
          <small>Settled stakes</small>
          <strong>{completed.length ? naira(staked) : "—"}</strong>
        </div>
        <div>
          <small>Gross payouts</small>
          <strong>{completed.length ? naira(payouts) : "—"}</strong>
        </div>
      </div>
      <div className="performance-grid">
        <ResearchChart runs={runs} initial={initial} />
        <aside className="goal-panel coverage-panel">
          <p className="eyebrow">Research coverage</p>
          <div
            className="win-donut"
            role="img"
            aria-label={`${wins} wins and ${losses} other completed runs`}
            style={{
              background: `conic-gradient(#81c9a0 ${rate}%, #e88f86 ${rate}% 100%)`,
            }}
          >
            <div>
              <strong>{completed.length ? `${rate.toFixed(1)}%` : "—"}</strong>
              <small>run win rate</small>
            </div>
          </div>
          <h2>{completed.length} completed runs</h2>
          <div className="coverage-counts">
            <span>
              <i className="positive">●</i> {wins} wins
            </span>
            <span>
              <i className="negative">●</i> {losses} other closes
            </span>
          </div>
          <p>{runs.length - completed.length} incomplete calculations</p>
          <div className="coverage-track">
            <i
              style={{
                width: `${runs.length ? (completed.length / runs.length) * 100 : 0}%`,
              }}
            />
          </div>
          <dl className="coverage-details">
            <div>
              <dt>Seasons assessed · all leagues</dt>
              <dd>{data.seasons_assessed ?? "—"}</dd>
            </div>
            <div>
              <dt>Closed-run drawdown</dt>
              <dd>{completed.length ? naira(drawdown) : "—"}</dd>
            </div>
            <div>
              <dt>Starting capital per run</dt>
              <dd>{initial !== null ? naira(initial) : "unknown"}</dd>
            </div>
          </dl>
        </aside>
      </div>
      <div className="strategy-scorecards">
        {strategies
          .filter((s) => strategy === "all" || s === strategy)
          .map((s) => {
            const rows = completed.filter((r) => r.strategy_id === s),
              total = rows.reduce((sum, r) => sum + r.net_minor!, 0);
            return (
              <article key={s}>
                <small>{s.replaceAll("-", " ")}</small>
                <strong className={total < 0 ? "negative" : "positive"}>
                  {rows.length ? naira(total) : "—"}
                </strong>
                <div
                  className="run-tiles"
                  aria-label={`${s} completed run outcomes`}
                >
                  {rows.map((r, i) => (
                    <span
                      key={i}
                      className={r.net_minor! >= 0 ? "tile-win" : "tile-loss"}
                      title={`${r.competition} · ${r.season_id} · ${naira(r.net_minor!)}`}
                    />
                  ))}
                </div>
                <small>
                  {rows.length} completed ·{" "}
                  {rows.filter((r) => r.status === "win").length} wins
                </small>
              </article>
            );
          })}
      </div>
    </>
  );
}
