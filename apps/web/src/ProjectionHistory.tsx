import { Fragment, useState } from "react";
import { naira } from "./api";
import type { SharedSimulation } from "./sharedSimulation";
import { SharedRunCalculation } from "./SharedRunCalculation";
import { HistoryFilters } from "./HistoryFilters";
import {
  matchesHistory,
  settledHistoryRun,
  emptyHistorySelection,
} from "./historySelection";
import "./history.css";

export function ProjectionHistory({ view }: { view: SharedSimulation }) {
  const [filters, setFilters] = useState(emptyHistorySelection);
  const [page, setPage] = useState(1);
  const [expanded, setExpanded] = useState<string | null>(null);
  const runs = [...view.runs, ...(view.unqualified ?? [])].sort(
    (a, b) =>
      (Date.parse(b.entry_at) || 0) - (Date.parse(a.entry_at) || 0) ||
      a.id.localeCompare(b.id),
  );
  const selected = runs.filter((run) => matchesHistory(run, filters));
  const settled = selected.filter(settledHistoryRun);
  const seasons = [
    ...new Set(selected.map((r) => `${r.competition}:${r.season_id}`)),
  ];
  const last = Math.max(1, Math.ceil(seasons.length / 10));
  const current = Math.min(page, last);
  const shown = new Set(seasons.slice((current - 1) * 10, current * 10));
  const sum = (
    field: "total_staked_minor" | "gross_payout_minor" | "net_minor",
  ) => settled.reduce((total, r) => total + (r[field] ?? 0), 0);
  return (
    <section
      className="paper-history history-page"
      aria-label="Strategy simulation history"
    >
      <header className="section-heading">
        <div>
          <p className="eyebrow">Research history</p>
          <h2>History</h2>
          <p className="subtle">Run entry times · WAT · newest first</p>
        </div>
        <span className="history-count">
          {selected.length} runs · {seasons.length} seasons
        </span>
      </header>
      <HistoryFilters
        value={filters}
        change={(next) => {
          setFilters(next);
          setPage(1);
          setExpanded(null);
        }}
      />
      <div className="history-summary" aria-label="Filtered settled totals">
        <div>
          <small>Settled runs</small>
          <strong>{settled.length}</strong>
        </div>
        <div>
          <small>Stake sum</small>
          <strong>{naira(sum("total_staked_minor"))}</strong>
        </div>
        <div>
          <small>Payout</small>
          <strong>{naira(sum("gross_payout_minor"))}</strong>
        </div>
        <div>
          <small>Settled net</small>
          <strong className={sum("net_minor") < 0 ? "negative" : "positive"}>
            {naira(sum("net_minor"))}
          </strong>
        </div>
      </div>
      <p className="subtle history-caption">
        Totals cover all filtered settled runs; skipped previews are excluded.
      </p>
      {!selected.length ? (
        <p className="history-empty" role="status">
          No scenario runs for this selection.
        </p>
      ) : (
        <div className="paper-table-scroll">
          <table className="history-table">
            <thead>
              <tr>
                <th>Entry · WAT</th>
                <th>League / season</th>
                <th>Strategy / team</th>
                <th>Result</th>
                <th>Staked</th>
                <th>Payout</th>
                <th>Net</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {selected
                .filter((r) => shown.has(`${r.competition}:${r.season_id}`))
                .map((run) => (
                  <Fragment key={run.id}>
                    <tr>
                      <td>
                        {run.entry_at &&
                        Number.isFinite(Date.parse(run.entry_at)) ? (
                          <>
                            <strong>
                              {new Date(run.entry_at).toLocaleDateString(
                                "en-GB",
                                {
                                  timeZone: "Africa/Lagos",
                                  day: "2-digit",
                                  month: "short",
                                  year: "numeric",
                                },
                              )}
                            </strong>
                            <small>
                              {new Date(run.entry_at).toLocaleTimeString(
                                "en-GB",
                                { timeZone: "Africa/Lagos" },
                              )}
                            </small>
                          </>
                        ) : (
                          "Unknown"
                        )}
                      </td>
                      <td>
                        {run.competition?.replace("virtual-", "")} /{" "}
                        {run.season_id}
                      </td>
                      <td>
                        <strong>{run.team ?? "Awaiting champion"}</strong>
                        <small>
                          {run.strategy_id
                            ?.replace("champion-", "")
                            .replaceAll("-", " ")}
                        </small>
                      </td>
                      <td>
                        <span
                          className={`history-status ${run.status === "win" ? "positive" : ""}`}
                        >
                          {run.status.replaceAll("_", " ")}
                        </span>
                      </td>
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
                      <td
                        className={(run.net_minor ?? 0) < 0 ? "negative" : ""}
                      >
                        {run.net_minor != null ? naira(run.net_minor) : "—"}
                      </td>
                      <td>
                        <button
                          aria-expanded={expanded === run.id}
                          aria-controls={`history-${run.id}`}
                          onClick={() =>
                            setExpanded(expanded === run.id ? null : run.id)
                          }
                        >
                          {expanded === run.id ? "Close" : "Inspect"}
                        </button>
                      </td>
                    </tr>
                    {expanded === run.id && (
                      <tr className="history-details">
                        <td colSpan={8} id={`history-${run.id}`}>
                          <SharedRunCalculation run={run} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
            </tbody>
          </table>
        </div>
      )}
      <nav className="browse-pagination" aria-label="Simulation history pages">
        <button disabled={current <= 1} onClick={() => setPage(current - 1)}>
          Previous
        </button>
        <span>
          Page {current} of {last} · 10 seasons per page
        </span>
        <button disabled={current >= last} onClick={() => setPage(current + 1)}>
          Next
        </button>
      </nav>
    </section>
  );
}
