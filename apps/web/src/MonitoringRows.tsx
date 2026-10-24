import type { Overview } from "./api";

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
    "Watching the next scheduled fixture. Paper decisions are recorded separately.",
  awaiting_result:
    "The scheduled start has passed; waiting for a confirmed result.",
  observed_win:
    "A win was observed within the window. This is not a funded trading result.",
  four_attempts_without_win:
    "Four observed fixtures ended without a win. The window has closed.",
  window_ended: "The season ended before the observation window could finish.",
  no_win_in_season:
    "No first win was found in the completed season; no trigger was created.",
};

export function MonitoringRows({
  rows,
  strategies,
  competitions,
}: {
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
          <span className="monitoring-state">
            {row.status.replaceAll("_", " ")}
          </span>
          <p>
            {(row.reason ? historyReasons[row.reason] : undefined) ??
              reasons[row.status] ??
              "An unrecognized monitoring state was received; no execution is implied."}
          </p>
          {row.team && (
            <p>
              {row.team}
              {row.trigger_day ? ` · first win day ${row.trigger_day}` : ""}
              {row.attempt ? ` · observation ${row.attempt}/4` : ""}
            </p>
          )}
          {row.next_match && (
            <p>
              Day {row.next_match.matchday} · {row.next_match.home_team} vs{" "}
              {row.next_match.away_team}
            </p>
          )}
        </li>
      ))}
    </ul>
  );
}
