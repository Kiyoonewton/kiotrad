import { useState } from "react";
import { naira } from "./api";
import { chartData, eventLabel, type ResearchRun } from "./researchChartData";

type Mode = "Line" | "Candles" | "Bars";
export function ResearchChart({
  runs,
  initial,
}: {
  runs: ResearchRun[];
  initial: number | null;
}) {
  const [mode, setMode] = useState<Mode>("Line");
  const [selection, setSelection] = useState(0);
  const { points, candles } = chartData(runs, initial ?? 0);
  const values =
    mode === "Bars"
      ? [0, ...points.map((p) => p.run.net_minor!)]
      : [initial ?? 0, ...points.map((p) => p.value)];
  const low = Math.min(...values),
    high = Math.max(...values),
    pad = Math.max(10000, (high - low) * 0.12);
  const min = low - pad,
    max = high + pad;
  const y = (value: number) => 275 - ((value - min) / (max - min)) * 240;
  const x = (index: number, count: number) =>
    100 + ((index + 0.5) / Math.max(count, 1)) * 750;
  const count = mode === "Candles" ? candles.length : points.length;
  const active = Math.min(selection, Math.max(0, count - 1));
  const point = points[active],
    candle = candles[active];
  return (
    <section className="chart-panel detailed-chart">
      <div className="section-heading">
        <div>
          <p className="eyebrow">All qualifying seasons</p>
          <h2>Cumulative simulation results</h2>
        </div>
        <div className="chart-modes" role="group" aria-label="Chart type">
          {(["Line", "Candles", "Bars"] as Mode[]).map((m) => (
            <button
              key={m}
              aria-pressed={mode === m}
              onClick={() => {
                setMode(m);
                setSelection(0);
              }}
            >
              {m}
            </button>
          ))}
        </div>
      </div>
      <p className="subtle">
        {mode === "Candles"
          ? "Daily open / high / low / close of the reference path · green up, red down"
          : mode === "Bars"
            ? "Profit or loss per completed run event · simultaneous runs combined"
            : initial !== null
              ? "Reference path: starting capital + cumulative independent-run net"
              : "Cumulative independent-run net · starting capital unavailable"}
      </p>
      {points.length ? (
        <>
          <svg
            viewBox="0 0 900 325"
            role="img"
            aria-label="Cumulative net of independent simulations in event order"
            onPointerMove={(e) => {
              const rect = e.currentTarget.getBoundingClientRect();
              setSelection(
                Math.max(
                  0,
                  Math.min(
                    count - 1,
                    Math.floor(
                      ((((e.clientX - rect.left) / rect.width) * 900 - 100) /
                        750) *
                        count,
                    ),
                  ),
                ),
              );
            }}
          >
            {[0, 1, 2, 3, 4].map((i) => {
              const v = min + ((max - min) * i) / 4;
              return (
                <g key={i}>
                  <line
                    x1="90"
                    x2="860"
                    y1={y(v)}
                    y2={y(v)}
                    stroke="#34443d"
                    strokeDasharray="3 6"
                  />
                  <text x="78" y={y(v) + 4} textAnchor="end">
                    {naira(Math.round(v))}
                  </text>
                </g>
              );
            })}
            <line
              x1="90"
              x2="860"
              y1={y(mode === "Bars" ? 0 : (initial ?? 0))}
              y2={y(mode === "Bars" ? 0 : (initial ?? 0))}
              stroke="#a3b6aa"
              strokeDasharray="5 5"
            />
            {mode === "Line" && (
              <polyline
                points={[
                  [95, y(initial ?? 0)],
                  ...points.map((p, i) => [x(i, count), y(p.value)]),
                ]
                  .map((p) => p.join(","))
                  .join(" ")}
                fill="none"
                stroke="#a6d7bb"
                strokeWidth="2.5"
              />
            )}
            {mode === "Candles" &&
              candles.map((c, i) => (
                <g
                  key={c.day}
                  stroke={c.close >= c.open ? "#81c9a0" : "#e88f86"}
                  fill={c.close >= c.open ? "#81c9a0" : "#e88f86"}
                >
                  <title>
                    {c.day}: open {naira(c.open)}, high {naira(c.high)}, low{" "}
                    {naira(c.low)}, close {naira(c.close)}
                  </title>
                  <line
                    x1={x(i, count)}
                    x2={x(i, count)}
                    y1={y(c.high)}
                    y2={y(c.low)}
                  />
                  <rect
                    x={x(i, count) - Math.min(14, 250 / count)}
                    y={Math.min(y(c.open), y(c.close))}
                    width={Math.min(28, 500 / count)}
                    height={Math.max(2, Math.abs(y(c.open) - y(c.close)))}
                  />
                </g>
              ))}
            {mode === "Bars" &&
              points.map((p, i) => (
                <rect
                  key={i}
                  x={x(i, count) - Math.min(9, 300 / count)}
                  y={Math.min(y(0), y(p.run.net_minor!))}
                  width={Math.max(1, Math.min(18, 600 / count))}
                  height={Math.max(1, Math.abs(y(0) - y(p.run.net_minor!)))}
                  fill={p.run.net_minor! >= 0 ? "#81c9a0" : "#e88f86"}
                >
                  <title>
                    {eventLabel(p.run.event_at!)} · {p.run.team} ·{" "}
                    {naira(p.run.net_minor!)}
                  </title>
                </rect>
              ))}
            <line
              x1={x(active, count)}
              x2={x(active, count)}
              y1="25"
              y2="280"
              stroke="#d6e3db"
              opacity=".35"
            />
            {[...new Set([0, Math.floor((count - 1) / 2), count - 1])].map(
              (i) => (
                <text key={i} x={x(i, count)} y="310" textAnchor="middle">
                  {mode === "Candles"
                    ? candles[i]?.day
                    : points[i]
                      ? eventLabel(points[i].run.event_at!)
                      : ""}
                </text>
              ),
            )}
          </svg>
          <div className="chart-readout" aria-live="polite">
            {mode === "Candles" && candle ? (
              <>
                <strong>
                  {candle.day} · {candle.count} runs
                </strong>
                <span>
                  O {naira(candle.open)} · H {naira(candle.high)} · L{" "}
                  {naira(candle.low)} · C {naira(candle.close)}
                </span>
              </>
            ) : point ? (
              <>
                <strong>
                  {point.run.team} · season {point.run.season_id}
                </strong>
                <span>
                  {eventLabel(point.run.event_at!)} WAT · run net{" "}
                  {naira(point.run.net_minor!)} · reference {naira(point.value)}
                </span>
              </>
            ) : null}
          </div>
          <input
            className="chart-scrubber"
            type="range"
            aria-label="Inspect chart event"
            min="0"
            max={Math.max(0, count - 1)}
            value={active}
            onChange={(e) => setSelection(Number(e.target.value))}
          />
        </>
      ) : (
        <div className="chart-empty">No completed priced simulations yet</div>
      )}
      <p className="subtle">
        Independent runs can overlap; this is not one shared bankroll. Candle
        values represent simulation results, not market prices.
      </p>
    </section>
  );
}
