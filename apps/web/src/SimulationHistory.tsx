import { useEffect, useState } from "react";
import { naira } from "./api";
import { getSimulationHistory, type SimulationPage } from "./history-api";
import { SharedRunCalculation } from "./SharedRunCalculation";
import { RunCalculation } from "./RunCalculation";

export function SimulationHistory({ league }: { league: string }) {
  const [page, setPage] = useState(1);
  const [date, setDate] = useState("");
  const [data, setData] = useState<SimulationPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    getSimulationHistory(page, league, controller.signal, date)
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted)
          setError(
            cause instanceof Error
              ? cause.message
              : "Unable to read simulation history.",
          );
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [page, league, refresh, date]);
  return (
    <section className="paper-history" aria-label="Strategy simulation history">
      <p className="eyebrow">Research simulation only</p>
      <h2>Simulation history.</h2>
      <p className="subtle">
        Newest event dates first · 10 seasons per page · shared-capital
        decisions.
      </p>
      <label>
        Season event date (Lagos){" "}
        <input
          type="date"
          value={date}
          onChange={(e) => {
            setLoading(true);
            setError(null);
            setDate(e.target.value);
            setPage(1);
          }}
        />
      </label>
      {loading && <p role="status">Calculating simulation history…</p>}
      {error && (
        <p role="alert">
          {error}{" "}
          <button
            onClick={() => {
              setLoading(true);
              setError(null);
              setRefresh((n) => n + 1);
            }}
          >
            Retry
          </button>
        </p>
      )}
      {!loading && !error && data && (
        <>
          {!data.rows.length ? (
            <p>No stored seasons on this page.</p>
          ) : (
            <div className="paper-table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>League / season</th>
                    <th>Strategy / team</th>
                    <th>Simulation result</th>
                    <th>Staked</th>
                    <th>Gross payout</th>
                    <th>Net</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((row) => {
                    const shared =
                      data.calculation_mode === "shared_capital_simulation";
                    const run = shared ? row.shared_run : row.simulation;
                    const priced =
                      run &&
                      ((run.attempts?.length ?? 0) > 0 ||
                        run.net_minor !== null);
                    return (
                      <tr
                        key={`${row.competition}:${row.season_id}:${row.strategy_id}`}
                      >
                        <td>
                          {row.competition.replace("virtual-", "")} /{" "}
                          {row.season_id}
                        </td>
                        <td>
                          {row.strategy_id?.replaceAll("-", " ")}
                          <br />
                          {row.team ?? "Awaiting champion"}
                          {run && (
                            <details>
                              <summary>View attempts</summary>
                              {shared ? (
                                <SharedRunCalculation
                                  run={row.shared_run ?? undefined}
                                />
                              ) : (
                                row.simulation && (
                                  <RunCalculation
                                    calculation={row.simulation}
                                  />
                                )
                              )}
                            </details>
                          )}
                        </td>
                        <td>
                          {(
                            run?.status ??
                            (shared &&
                            row.shared_calculation_status !== "available"
                              ? "shared_calculation_refreshing"
                              : row.status)
                          ).replaceAll("_", " ")}
                        </td>
                        <td>
                          {priced ? naira(run!.total_staked_minor ?? 0) : "—"}
                        </td>
                        <td>
                          {priced ? naira(run!.gross_payout_minor ?? 0) : "—"}
                        </td>
                        <td>
                          {run?.net_minor != null
                            ? naira(run!.net_minor!)
                            : "—"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
          <nav aria-label="Simulation history pages">
            <button
              disabled={page <= 1}
              onClick={() => {
                setLoading(true);
                setError(null);
                setPage((p) => p - 1);
              }}
            >
              Previous
            </button>{" "}
            <span>
              Page {page} of {data.pagination.last_page} ·{" "}
              {data.pagination.total} stored seasons
            </span>{" "}
            <button
              disabled={page >= data.pagination.last_page}
              onClick={() => {
                setLoading(true);
                setError(null);
                setPage((p) => p + 1);
              }}
            >
              Next
            </button>
          </nav>
        </>
      )}
    </section>
  );
}
