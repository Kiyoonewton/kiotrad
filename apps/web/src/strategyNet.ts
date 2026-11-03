import type { SharedRun } from "./sharedSimulation";

export function settledStrategyNet(runs: SharedRun[]) {
  return runs
    .filter(
      (run) =>
        run.estimate &&
        run.funded !== false &&
        !run.status.startsWith("skipped_") &&
        run.net_minor != null &&
        run.released_at,
    )
    .reduce((sum, run) => sum + (run.net_minor ?? 0), 0);
}
