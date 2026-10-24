import { naira, type Overview } from "./api";

export function PaperCapital({ data }: { data: Overview }) {
  const points = data.capital_history ?? [];
  if (data.bankroll?.status !== "paper" || points.length < 2)
    return (
      <div className="chart-message">
        <span className="chart-symbol" aria-hidden="true">
          ↗
        </span>
        <h3>
          {data.bankroll?.status === "paper"
            ? "Waiting for the first paper settlement."
            : "Your story starts with the first run."}
        </h3>
        <p>
          Settled paper attempts will build this chart.
          <br />
          No projected returns are displayed.
        </p>
      </div>
    );
  const min = Math.min(...points.map((p) => p.balance_minor));
  const max = Math.max(...points.map((p) => p.balance_minor));
  const range = Math.max(max - min, 10000);
  const path = points
    .map(
      (p, i) =>
        `${20 + (i / (points.length - 1)) * 760},${180 - ((p.balance_minor - min) / range) * 150}`,
    )
    .join(" ");
  return (
    <div className="paper-chart">
      <p>
        {naira(points[0].balance_minor)} →{" "}
        {naira(points[points.length - 1].balance_minor)}
      </p>
      <svg
        viewBox="0 0 800 210"
        role="img"
        aria-label={`Paper capital after ${points.length - 1} settled attempts`}
      >
        <polyline points={path} fill="none" stroke="#9acbb2" strokeWidth="2" />
      </svg>
      <small>
        Chronological settlements · available cash plus reserved stake · no
        future projection
      </small>
    </div>
  );
}
