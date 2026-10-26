import { naira } from "./api";
import { eventLabel } from "./researchChartData";
import type { MapRun } from "./runMapData";
export function RunMapInspector({ run }: { run: MapRun }) {
  return (
    <div className="timeline-inspector" aria-live="polite">
      <strong>
        {run.team} · {run.strategy_id.replaceAll("-", " ")}
      </strong>
      <span>
        Season {run.season_id} · {run.attempts ?? "—"} attempts
      </span>
      <span>
        {eventLabel(run.started_at ?? run.event_at!)} →{" "}
        {eventLabel(run.event_at!)} WAT
      </span>
      {run.span_basis && <span>{run.span_basis === "modeled_settlement_not_event_finish" ? "Simulation settlement window · finish time modeled" : "Scheduled attempt window · finish time unverified"}</span>}
      {run.result_observed_at && <span>Result downloaded: {eventLabel(run.result_observed_at)} WAT</span>}
      {run.overlap_basis && <span>{run.overlap_basis === "modeled_settlement_offset" ? "Overlap under simulation settlement model" : "Overlap timing unverified · " + (run.overlap_basis === "unverified_late_archive_timing" ? "late archive confirmation" : "recorded confirmation upper bound")}</span>}
      {run.run_kind === "skipped" ? (
        <span>
          Skipped · {run.observed_result?.replaceAll("_", " ") ?? "pending"} ·
          not included in profit
        </span>
      ) : (
        <span>
          {run.net_minor == null
            ? "Pending result"
            : `${naira(run.net_minor)} net`}
        </span>
      )}
      {run.overlap_with && <span>Active run: {run.overlap_with}</span>}
    </div>
  );
}
