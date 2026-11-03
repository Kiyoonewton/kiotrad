import { Fragment, useState } from "react";
import { naira } from "./api";
import type { SharedRun } from "./sharedSimulation";
import { SharedRunCalculation } from "./SharedRunCalculation";

export function RunBrowser({
  runs,
  qualifiedOnly = false,
}: {
  runs: SharedRun[];
  qualifiedOnly?: boolean;
}) {
  const [expanded, setExpanded] = useState<string | null>(null);
  const [filter, setFilter] = useState("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const selected = runs.filter((r) => {
    if (qualifiedOnly && r.status === "not_qualified") return false;
    const skipped = r.status.startsWith("skipped_");
    return (
      (filter === "all" ||
        (filter === "qualified"
          ? r.status !== "not_qualified"
          : filter === "skipped"
            ? skipped
            : !skipped && r.net_minor != null && !!r.released_at)) &&
      `${r.team ?? ""} ${r.season_id ?? ""} ${r.competition ?? ""} ${r.id}`
        .toLowerCase()
        .includes(search.toLowerCase())
    );
  });
  if (qualifiedOnly)
    selected.sort((a, b) => {
      const time = (r: SharedRun) =>
        Date.parse(r.entry_at || r.fixtures?.[0]?.scheduled_start_at || "") ||
        0;
      return (
        time(b) - time(a) ||
        Number(b.season_id ?? 0) - Number(a.season_id ?? 0) ||
        a.id.localeCompare(b.id)
      );
    });
  const last = Math.max(1, Math.ceil(selected.length / 12));
  const current = Math.min(page, last);
  return (
    <section className="run-browser" aria-label="Run explorer">
      <div className="section-heading">
        <div>
          <p className="eyebrow">Opportunities</p>
          <h2>Run explorer</h2>
        </div>
        <span className="subtle">
          {selected.length} / {runs.length} runs
        </span>
      </div>
      <div className="browse-toolbar">
        <div className="browse-tabs" role="group" aria-label="Run status">
          {[
            ...(qualifiedOnly
              ? [["all", "Qualified"]]
              : [
                  ["all", "All"],
                  ["qualified", "Qualified"],
                ]),
            ["settled", "Settled"],
            ["skipped", "Skipped"],
          ].map(([value, label]) => (
            <button
              key={value}
              aria-pressed={filter === value}
              onClick={() => {
                setFilter(value);
                setPage(1);
              }}
            >
              {label}
            </button>
          ))}
        </div>
        <label className="browse-search">
          <span className="sr-only">Search runs</span>
          <input
            type="search"
            placeholder="Team or season…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </label>
      </div>
      <div className="paper-table-scroll">
        <table className="browse-table">
          <thead>
            <tr>
              <th>Team / season</th>
              <th>Status</th>
              <th>Attempts to win</th>
              <th>Staked</th>
              <th>Payout</th>
              <th>Net</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {selected.slice((current - 1) * 12, current * 12).map((source) => {
              const hypothetical = source.hypothetical;
              const r =
                hypothetical && hypothetical.status !== "unavailable"
                  ? { ...source, ...hypothetical }
                  : source;
              return (
                <Fragment key={r.id}>
                  <tr>
                    <td>
                      <strong>{r.team ?? r.id}</strong>
                      <small>
                        {r.competition?.replace("virtual-", "")} · {r.season_id}{" "}
                        ·{" "}
                        {r.strategy_id
                          ?.replace("champion-", "")
                          .replaceAll("-", " ")}
                      </small>
                    </td>
                    <td>
                      <span
                        className={`browse-status ${r.status === "win" ? "positive" : ""}`}
                      >
                        {source.status.replaceAll("_", " ")}
                        {hypothetical && (
                          <small>
                            Hypothetical ·{" "}
                            {hypothetical.status.replaceAll("_", " ")}
                          </small>
                        )}
                      </span>
                    </td>
                    <td>
                      {r.status === "win" && r.attempts?.length
                        ? (r.attempts.find((a) => (a.payout_minor ?? 0) > 0)
                            ?.attempt ?? r.attempts.length)
                        : "—"}
                    </td>
                    <td>
                      {r.total_staked_minor != null
                        ? naira(r.total_staked_minor)
                        : "—"}
                    </td>
                    <td>
                      {r.gross_payout_minor != null
                        ? naira(r.gross_payout_minor)
                        : "—"}
                    </td>
                    <td
                      className={
                        r.net_minor != null && r.net_minor < 0
                          ? "negative"
                          : "positive"
                      }
                    >
                      {r.net_minor != null ? naira(r.net_minor) : "—"}
                    </td>
                    <td>
                      <button
                        className="browse-inspect"
                        aria-expanded={expanded === r.id}
                        aria-controls={`run-detail-${r.id}`}
                        onClick={() =>
                          setExpanded(expanded === r.id ? null : r.id)
                        }
                      >
                        {expanded === r.id ? "Close" : "Inspect"}
                      </button>
                    </td>
                  </tr>
                  {expanded === r.id && (
                    <tr className="browse-expanded">
                      <td colSpan={7} id={`run-detail-${r.id}`}>
                        <div className="browse-detail">
                          <p>{r.id}</p>
                          {r.trigger_day != null && (
                            <p>First win · D{r.trigger_day}</p>
                          )}
                          {r.entry_at && (
                            <p>
                              Entry ·{" "}
                              {new Date(r.entry_at).toLocaleString("en-GB", {
                                timeZone: "Africa/Lagos",
                              })}{" "}
                              WAT
                            </p>
                          )}
                          {r.overlap_basis && (
                            <p>
                              Overlap · {r.overlap_basis.replaceAll("_", " ")}
                            </p>
                          )}
                          {r.blocked_by && <p>Blocked by · {r.blocked_by}</p>}
                          {r.entry_issue && (
                            <p>{r.entry_issue.replaceAll("_", " ")}</p>
                          )}
                          {r.missing_historical_fixtures?.map((f) => (
                            <p key={f.provider_match_id ?? f.matchday}>
                              D{f.matchday} · {f.home_team} vs {f.away_team} ·{" "}
                              {f.outcome}: earlier-season odds missing
                            </p>
                          ))}
                          {hypothetical && (
                            <p>
                              Hypothetical calculation · excluded from bankroll
                              and totals.
                            </p>
                          )}
                          {hypothetical?.reason && (
                            <p>{hypothetical.reason.replaceAll("_", " ")}</p>
                          )}
                          <SharedRunCalculation run={r} />
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
      {!selected.length && (
        <p className="browse-empty" role="status">
          No runs match these filters.
        </p>
      )}
      <nav className="browse-pagination" aria-label="Run explorer pages">
        <button disabled={current <= 1} onClick={() => setPage(current - 1)}>
          Previous
        </button>
        <span>
          {current} / {last}
        </span>
        <button disabled={current >= last} onClick={() => setPage(current + 1)}>
          Next
        </button>
      </nav>
    </section>
  );
}
