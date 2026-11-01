import { useState } from "react";
import { naira } from "./api";
import type { SharedSimulation } from "./sharedSimulation";
import { SharedRunCalculation } from "./SharedRunCalculation";

export function ProjectionHistory({ view }: { view: SharedSimulation }) {
  const [date, setDate] = useState("");
  const [page, setPage] = useState(1);
  const runs = [...view.runs].sort(
    (a, b) =>
      (b.entry_at ?? "").localeCompare(a.entry_at ?? "") ||
      a.id.localeCompare(b.id),
  );
  const selected = runs.filter(
    (r) =>
      !date ||
      (r.entry_at &&
        new Intl.DateTimeFormat("en-CA", {
          timeZone: "Africa/Lagos",
          year: "numeric",
          month: "2-digit",
          day: "2-digit",
        }).format(new Date(r.entry_at)) === date),
  );
  const seasons = [
    ...new Set(selected.map((r) => `${r.competition}:${r.season_id}`)),
  ];
  const last = Math.max(1, Math.ceil(seasons.length / 10));
  const current = Math.min(page, last);
  const shown = new Set(seasons.slice((current - 1) * 10, current * 10));
  return (
    <section className="paper-history" aria-label="Strategy simulation history">
      <p className="eyebrow">Projected history</p>
      <h2>Simulation history.</h2>
      <p className="subtle">Newest entry first · 10 seasons per page.</p>
      <label>
        Run entry date (Lagos){" "}
        <input
          type="date"
          value={date}
          onChange={(e) => {
            setDate(e.target.value);
            setPage(1);
          }}
        />
      </label>
      {!selected.length ? (
        <p>No scenario runs for this selection.</p>
      ) : (
        <div className="paper-table-scroll">
          <table>
            <thead>
              <tr>
                <th>League / season</th>
                <th>Strategy / team</th>
                <th>Result</th>
                <th>Staked</th>
                <th>Gross payout</th>
                <th>Net</th>
              </tr>
            </thead>
            <tbody>
              {selected
                .filter((r) => shown.has(`${r.competition}:${r.season_id}`))
                .map((run) => (
                  <tr key={run.id}>
                    <td>
                      {run.competition?.replace("virtual-", "")} /{" "}
                      {run.season_id}
                    </td>
                    <td>
                      {run.strategy_id?.replaceAll("-", " ")}
                      <br />
                      {run.team ?? "Awaiting champion"}
                      <details>
                        <summary>View attempts</summary>
                        <SharedRunCalculation run={run} />
                      </details>
                    </td>
                    <td>{run.status.replaceAll("_", " ")}</td>
                    <td>
                      {run.total_staked_minor != null
                        ? naira(run.total_staked_minor)
                        : "—"}
                    </td>
                    <td>
                      {run.gross_payout_minor != null
                        ? naira(run.gross_payout_minor)
                        : "—"}
                    </td>
                    <td>
                      {run.net_minor != null ? naira(run.net_minor) : "—"}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}
      <nav aria-label="Simulation history pages">
        <button disabled={current <= 1} onClick={() => setPage(current - 1)}>
          Previous
        </button>{" "}
        Page {current} of {last} · {seasons.length} scenario seasons{" "}
        <button disabled={current >= last} onClick={() => setPage(current + 1)}>
          Next
        </button>
      </nav>
    </section>
  );
}
