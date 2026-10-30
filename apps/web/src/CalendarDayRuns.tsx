import { naira } from "./api";
import type { SharedRun } from "./sharedSimulation";

const timestamp = (value: string) =>
  new Date(value).toLocaleString("en-GB", {
    timeZone: "Africa/Lagos",
    hour12: false,
  });
const leagueName = (id?: string) =>
  ({
    "virtual-football": "English",
    "virtual-bundesliga": "Bundesliga",
    "virtual-euro": "Euro",
  })[id ?? ""] ??
  id ??
  "—";
const strategyName = (id?: string) =>
  ({ "champion-next-win": "Next Win", "champion-next-season": "Next Season" })[
    id ?? ""
  ] ??
  id ??
  "—";

export function CalendarDayRuns({
  date,
  runs,
  modeled,
}: {
  date: string;
  runs: SharedRun[];
  modeled: boolean;
}) {
  return (
    <section aria-label={`Runs on ${date}`} className="calendar-day-runs">
      <p>
        {date} · individual accepted runs · all times WAT
        {modeled
          ? " · release times use the settlement model"
          : " · release times use recorded simulation timing"}
      </p>
      <table
        className="calendar-daily-table"
        aria-label={`Run details for ${date}`}
      >
        <thead>
          <tr>
            <th scope="col">Run</th>
            <th scope="col">Entry date & time</th>
            <th scope="col">Release date & time</th>
            <th scope="col">Result</th>
            <th scope="col">Profit / loss</th>
            <th scope="col">Stake sum</th>
            <th scope="col">Payout</th>
          </tr>
        </thead>
        <tbody>
          {runs.map((run) => (
            <tr key={run.id}>
              <th scope="row">
                {run.team ?? "Unknown team"}
                <small>
                  {leagueName(run.competition)} ·{" "}
                  {strategyName(run.strategy_id)} · season{" "}
                  {run.season_id ?? "—"}
                </small>
              </th>
              <td>{timestamp(run.entry_at)}</td>
              <td>{timestamp(run.released_at!)}</td>
              <td>{run.status.replaceAll("_", " ")}</td>
              <td
                className={
                  run.net_minor! < 0 ? "calendar-loss" : "calendar-profit"
                }
              >
                {naira(run.net_minor!)}
              </td>
              <td>{naira(run.total_staked_minor ?? 0)}</td>
              <td>{naira(run.gross_payout_minor ?? 0)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
