import { naira } from "./api";
import { chartData, eventLabel } from "./researchChartData";
type Data = ReturnType<typeof chartData>;
export function ChartReadout({
  mode,
  candle,
  point,
  shared,
}: {
  mode: string;
  candle?: Data["candles"][number];
  point?: Data["points"][number];
  shared: boolean;
}) {
  return (
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
            {naira(point.run.net_minor!)} · {shared ? "balance" : "reference"}{" "}
            {naira(point.value)}
          </span>
          {shared && (
            <small>
              {point.run.status.replaceAll("_", " ")} · staked{" "}
              {naira(point.run.total_staked_minor)} · payout{" "}
              {naira(point.run.gross_payout_minor)}
            </small>
          )}
        </>
      ) : null}
    </div>
  );
}
