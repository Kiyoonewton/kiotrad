import { naira, type Overview } from "./api";

export function ResearchPerformance({
  data,
  league,
}: {
  data: NonNullable<Overview["research_simulation"]>;
  league: string;
}) {
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
    (r) => league === "all" || r.competition === league,
  );
  const completed = runs.filter((r) => r.net_minor !== null);
  const net = completed.reduce((sum, r) => sum + (r.net_minor ?? 0), 0);
  const priced = completed
    .filter((r) => r.event_at)
    .sort((a, b) => a.event_at!.localeCompare(b.event_at!));
  const byEvent = new Map<string, number>();
  for (const run of priced)
    byEvent.set(
      run.event_at!,
      (byEvent.get(run.event_at!) ?? 0) + run.net_minor!,
    );
  const values = [0];
  for (const value of byEvent.values())
    values.push(values[values.length - 1] + value);
  const low = Math.min(...values),
    high = Math.max(...values),
    span = high - low || 1;
  const points = values
    .map(
      (value, i) =>
        `${20 + (i / Math.max(1, values.length - 1)) * 720},${220 - ((value - low) / span) * 190}`,
    )
    .join(" ");
  return (
    <>
      <section className="balance-header" aria-labelledby="research-title">
        <div>
          <p className="eyebrow">Evaluate strategies over time</p>
          <h1 id="research-title">Research simulation.</h1>
        </div>
        <div className="balance-value">
          <p>Completed independent-run net</p>
          <strong>{completed.length ? naira(net) : "₦ —"}</strong>
          <span>Latest seven days · event order</span>
        </div>
      </section>
      <div className="performance-grid">
        <section className="chart-panel" aria-labelledby="research-chart-title">
          <div className="section-heading">
            <h2 id="research-chart-title">Cumulative simulation results</h2>
            <span className="subtle">Completed runs</span>
          </div>
          {priced.length ? (
            <svg
              viewBox="0 0 760 250"
              role="img"
              aria-label="Cumulative net of independent simulations in event order"
              style={{ width: "100%", minHeight: 180 }}
            >
              <line
                x1="20"
                x2="740"
                y1={220 - ((0 - low) / span) * 190}
                y2={220 - ((0 - low) / span) * 190}
                stroke="#56615a"
                strokeDasharray="4 6"
              />
              <polyline
                points={points}
                fill="none"
                stroke="#9DBBA8"
                strokeWidth="3"
              />
            </svg>
          ) : (
            <p>No completed priced simulations in this period yet.</p>
          )}
          <p className="subtle">
            Runs are evaluated independently and can overlap. This is a research
            comparison, not one shared bankroll or a doubling forecast.
          </p>
        </section>
        <aside className="goal-panel">
          <p className="eyebrow">Research coverage</p>
          <h2>{completed.length} completed runs</h2>
          <p>
            {completed.filter((r) => r.status === "win").length} wins ·{" "}
            {runs.length - completed.length} incomplete calculations
          </p>
          <p>
            Starting capital per run:{" "}
            {data.starting_capital_per_run_minor != null
              ? naira(data.starting_capital_per_run_minor)
              : "unknown"}
          </p>
          <p>
            Recovered matches count in event order. Missing source data stays
            visible.
          </p>
        </aside>
      </div>
    </>
  );
}
