import type { SharedRun } from "./sharedSimulation";
export type HistorySelection = {
  from: string;
  to: string;
  strategy: string;
  result: string;
  search: string;
};
export const emptyHistorySelection: HistorySelection = {
  from: "",
  to: "",
  strategy: "all",
  result: "all",
  search: "",
};

export function lagosDate(value: string) {
  if (!value || !Number.isFinite(Date.parse(value))) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Lagos",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date(value));
  const part = (name: string) => parts.find((p) => p.type === name)?.value;
  return `${part("year")}-${part("month")}-${part("day")}`;
}
export function settledHistoryRun(run: SharedRun) {
  return (
    !run.status.startsWith("skipped_") &&
    run.status !== "not_qualified" &&
    run.funded !== false &&
    run.net_minor != null &&
    !!run.released_at
  );
}
export function matchesHistory(run: SharedRun, selection: HistorySelection) {
  const date = lagosDate(run.entry_at);
  const settled = settledHistoryRun(run);
  const result = selection.result;
  return (
    (!selection.from || date >= selection.from) &&
    (!selection.to || (!!date && date <= selection.to)) &&
    (selection.strategy === "all" || run.strategy_id === selection.strategy) &&
    (result === "all" ||
      (result === "settled" && settled) ||
      (result === "win" && run.status === "win") ||
      (result === "loss" && settled && (run.net_minor ?? 0) < 0) ||
      (result === "skipped" && run.status.startsWith("skipped_")) ||
      (result === "not_qualified" && run.status === "not_qualified") ||
      (result === "pending" &&
        !settled &&
        !run.status.startsWith("skipped_") &&
        run.status !== "not_qualified")) &&
    `${run.team ?? ""} ${run.season_id ?? ""} ${run.competition ?? ""}`
      .toLowerCase()
      .includes(selection.search.toLowerCase().trim())
  );
}
