import type { Overview } from "./api";
import type { SharedSimulation } from "./sharedSimulation";
export type MapRun = NonNullable<
  Overview["research_simulation"]
>["runs"][number];
export function runMapColor(run: MapRun) {
  if (run.run_kind === "skipped")
    return run.observed_result === "win"
      ? "#62aee8"
      : run.observed_result === "no_win"
        ? "#d8ab62"
        : "#8c99a2";
  return run.net_minor == null
    ? "#8c99a2"
    : run.net_minor >= 0
      ? "#81c9a0"
      : "#e88f86";
}
export function sharedRunMap(
  data: SharedSimulation,
): Overview["research_simulation"] {
  return {
    status: data.status,
    runs: data.runs
      .filter(
        (r) =>
          r.status === "skipped_overlap" ||
          r.status === "skipped_simultaneous_entry" ||
          (r.estimate && !r.status.startsWith("skipped_")),
      )
      .map((r) => {
        const skipped = r.status.startsWith("skipped_");
        return {
          competition: r.competition!,
          season_id: r.season_id!,
          strategy_id: r.strategy_id!,
          team: r.team!,
          status: r.status,
          net_minor: skipped ? null : (r.net_minor ?? null),
          total_staked_minor: skipped ? 0 : (r.total_staked_minor ?? 0),
          gross_payout_minor: skipped ? 0 : (r.gross_payout_minor ?? 0),
          started_at: r.entry_at,
          // The map shows scheduled attempts, never archive download duration.
          event_at: data.settlement_model
            ? (r.simulation_settlement_at ?? r.observed_window?.ended_at ?? r.entry_at)
            : skipped
            ? (r.fixtures?.find((f) => f.won === true)?.scheduled_start_at ??
              r.fixtures?.at(-1)?.scheduled_start_at ?? r.entry_at)
            : (r.attempts?.at(-1)?.scheduled_start_at ?? r.entry_at),
          span_basis: data.settlement_model ? "modeled_settlement_not_event_finish" : "scheduled_attempts_not_event_finish",
          result_observed_at: r.result_observed_at ?? r.released_at ?? r.observed_window?.ended_at,
          overlap_basis: r.overlap_basis,
          attempts: r.attempts?.length,
          run_kind: skipped ? "skipped" : "selected",
          observed_result: r.observed_window?.result,
          overlap_with: r.blocked_by,
        };
      }),
  };
}
