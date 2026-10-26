import { naira } from "./api";
import type { SharedRun } from "./sharedSimulation";
import type { Overview } from "./api";
import { RunCalculation } from "./RunCalculation";

const historyReasons: Record<string, string> = {
  missing_linked_previous_season:
    "No unique recorded link to the previous season is available yet.",
  previous_champion_unverified:
    "The linked previous season’s champion has not been verified.",
  season_link_not_verified:
    "The season change was not verified, or collection had an unconfirmed gap.",
  missing_linked_older_season:
    "Next Season needs one more recorded season link to assess the preceding irregularity.",
  older_champion_unverified:
    "The older season’s champion has not been verified.",
  earlier_season_link_not_verified:
    "The earlier season change was not verified; this transition cannot qualify.",
};

const reasons: Record<string, string> = {
  waiting_for_history:
    "Needs linked seasons and a verified champion; snapshot count alone is not enough.",
  waiting_for_collection: "Waiting for the first collector observation.",
  monitoring_unavailable:
    "Monitoring could not read its data. Collection status is shown separately.",
  awaiting_first_win:
    "Waiting for consecutive confirmed results through the champion’s first win.",
  not_qualified:
    "The champion’s first win came before day four, so this season does not qualify.",
  late_champion_evidence:
    "The champion was not verified before the opening fixture, or that fixture’s time is missing.",
  late_evidence:
    "Required evidence arrived after the next fixture’s scheduled start.",
  missing_fixture:
    "A required fixture or its scheduled start is missing or ambiguous.",
  watching_fixture:
    "Tracking the next scheduled fixture in this strategy simulation.",
  awaiting_result:
    "The scheduled start has passed; waiting for a confirmed result.",
  observed_win:
    "The strategy’s win condition was met within the four-match simulation window.",
  four_attempts_without_win:
    "Four observed fixtures ended without a win. The window has closed.",
  window_ended: "The season ended before the observation window could finish.",
  no_win_in_season:
    "No first win was found in the completed season; no trigger was created.",
};

export function MonitoringRows({
  rows,
  sharedRuns,
  strategies,
  competitions,
}: {
  sharedRuns?: SharedRun[];
  rows: NonNullable<Overview["strategy_monitoring"]>;
  strategies: Overview["strategies"];
  competitions: Overview["competitions"];
}) {
  if (!rows.length)
    return (
      <p className="subtle">
        No monitoring observations returned for this selection.
      </p>
    );
  return (
    <ul className="monitoring-rows">
      {rows.map((row) => (
        <li key={`${row.competition}:${row.strategy_id ?? "availability"}`}>
          <div className="monitoring-heading">
            <strong>
              {competitions.find((c) => c.id === row.competition)?.name ??
                row.competition}
            </strong>
            <span>
              {strategies.find((s) => s.id === row.strategy_id)?.name ??
                "Monitoring availability"}
            </span>
          </div>
          <span className={`monitoring-state state-${row.status}`}>
            {row.status === "observed_win"
              ? "✓ Win observed"
              : row.status.replaceAll("_", " ")}
          </span>
          {row.team && <h3 className="run-team">{row.team}</h3>}
          <div className="run-meta">
            {row.trigger_day ? (
              <span>First win · D{row.trigger_day}</span>
            ) : null}
            {row.next_match && (
              <span>
                D{row.next_match.matchday} · {row.next_match.home_team} vs{" "}
                {row.next_match.away_team}
              </span>
            )}
          </div>
          {!!row.attempt && (
            <div
              className="observation-track"
              aria-label={`Observed window: ${row.attempt ?? 0} of 4 attempts`}
            >
              {[1, 2, 3, 4].map((n) => (
                <span
                  key={n}
                  className={
                    n === row.attempt && row.status === "observed_win"
                      ? "track-win"
                      : n < (row.attempt ?? 0) ||
                          (n === row.attempt &&
                            row.status === "four_attempts_without_win")
                        ? "track-seen"
                        : ""
                  }
                >
                  <b>
                    {n === row.attempt && row.status === "observed_win"
                      ? "✓"
                      : n}
                  </b>
                  <small>
                    {n === row.attempt && row.status === "observed_win"
                      ? "Win"
                      : n < (row.attempt ?? 0) ||
                          (n === row.attempt &&
                            row.status === "four_attempts_without_win")
                        ? "Observed"
                        : "—"}
                  </small>
                </span>
              ))}
            </div>
          )}
          <details className="run-details">
            <summary>Qualification details</summary>
            <p>
              {(row.reason ? historyReasons[row.reason] : undefined) ??
                reasons[row.status] ??
                "Monitoring state unavailable."}
            </p>
          </details>
          {sharedRuns
            ? (() => {
                const run = sharedRuns.find(
                  (r) =>
                    r.id ===
                    `${row.competition}:${row.season_id}:${row.strategy_id}`,
                );
                return (
                  <div className="run-calculation">
                    <strong>
                      {run?.status.replaceAll("_", " ") ??
                        "No shared-capital entry"}
                    </strong>
                    {run?.estimate && (
                      <p>
                        Frozen profit / attempt{" "}
                        {naira(run.estimate.plan.profit_per_attempt_minor)}
                      </p>
                    )}
                    {run?.total_staked_minor != null && (
                      <p>
                        Staked {naira(run.total_staked_minor)} · net{" "}
                        {run.net_minor != null
                          ? naira(run.net_minor)
                          : "unresolved"}
                      </p>
                    )}
                  </div>
                );
              })()
            : row.simulation && <RunCalculation calculation={row.simulation} />}
          {!row.simulation && !!row.data_quality_notes?.length && (
            <details className="run-details">
              <summary>Data notes</summary>
              {row.data_quality_notes.map((note) => (
                <p key={note}>{note}</p>
              ))}
            </details>
          )}
        </li>
      ))}
    </ul>
  );
}
