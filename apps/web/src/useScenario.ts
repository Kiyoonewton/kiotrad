import { useEffect, useState } from "react";
import type { SharedSimulation } from "./sharedSimulation";

export type ScenarioSettings = {
  capital: number;
  capital_mode?: "compound" | "fixed";
  profit_stop?: number;
  qualification: number;
  attempts: number;
  allowance: number;
  strategy: string;
};
export type ScenarioView = SharedSimulation & {
  source_as_of: string;
  data_revision: string;
  settings: ScenarioSettings;
  scenario_cache: string;
};
export function validScenario(s: ScenarioSettings) {
  return (
    Number.isInteger(s.capital) &&
    s.capital >= 100 &&
    s.capital <= 10000000 &&
    Number.isInteger(s.qualification) &&
    s.qualification >= 1 &&
    s.qualification <= 30 &&
    Number.isInteger(s.attempts) &&
    s.attempts >= 1 &&
    s.attempts <= 10 &&
    [1, 2].includes(s.allowance) &&
    ["compound", "fixed"].includes(s.capital_mode ?? "compound") &&
    Number.isInteger(s.profit_stop ?? 0) &&
    (s.profit_stop ?? 0) >= 0 && (s.profit_stop ?? 0) <= 10000000
  );
}
export function initialScenario(capital: number): ScenarioSettings {
  const fallback: ScenarioSettings = {
    capital_mode: "compound",
    profit_stop: 0,
    capital,
    qualification: 5,
    attempts: 4,
    allowance: 1,
    strategy: "all",
  };
  try {
    const saved = JSON.parse(
      localStorage.getItem("kiotrad:simulator:v1") ?? "null",
    );
    return saved && validScenario(saved) && typeof saved.strategy === "string"
      ? { ...fallback, ...saved }
      : fallback;
  } catch {
    return fallback;
  }
}
export function useScenario(settings: ScenarioSettings, league: string) {
  const [state, setState] = useState<{
    key: string;
    view: ScenarioView | null;
    message: string;
  }>({ key: "", view: null, message: "" });
  const key = JSON.stringify({ ...settings, league });
  useEffect(() => {
    if (!validScenario(settings)) return;
    try {
      localStorage.setItem("kiotrad:simulator:v1", JSON.stringify(settings));
    } catch {
      /* Browser storage is optional. */
    }
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const refresh = async () => {
      try {
        const query = new URLSearchParams(
          Object.entries(JSON.parse(key)).map(([k, v]) => [k, String(v)]),
        );
        const response = await fetch(`/api/v1/research/scenario?${query}`, {
          signal: controller.signal,
          headers: { Accept: "application/json" },
        });
        const data = await response.json();
        if (controller.signal.aborted) return;
        if (response.status === 202) {
          const phases: Record<string, string> = {
            fixture_baselines: "Preparing historical prices",
            season_monitoring: "Checking seasons",
            run_evidence: "Preparing simulation runs",
          };
          const progress = data.preparation;
          const phase = phases[progress?.phase];
          setState((previous) => ({
            key,
            view: previous.key === key ? previous.view : null,
            message:
              phase &&
              Number.isInteger(progress.done) &&
              Number.isInteger(progress.total)
                ? `${phase} · ${progress.done}/${progress.total}`
                : (data.message ?? "Preparing reusable evidence…"),
          }));
          timer = setTimeout(refresh, 3000);
        } else if (!response.ok || data.status !== "available") {
          setState((previous) => ({
            key,
            view: previous.key === key ? previous.view : null,
            message:
              data.message ?? "Scenario unavailable. Check the worker logs.",
          }));
          timer = setTimeout(refresh, 10000);
        } else {
          setState({
            key,
            view: data,
            message:
              data.preparation?.status === "building"
                ? "Updating evidence; showing the previous prepared view."
                : data.preparation?.status === "failed"
                  ? "Evidence refresh failed; showing the previous prepared view. The worker will retry."
                  : "",
          });
          timer = setTimeout(refresh, 30000);
        }
      } catch {
        if (!controller.signal.aborted) {
          setState((previous) => ({
            key,
            view: previous.key === key ? previous.view : null,
            message: "Scenario connection interrupted. Retrying…",
          }));
          timer = setTimeout(refresh, 5000);
        }
      }
    };
    timer = setTimeout(refresh, 400);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
    // The serialized key includes every setting and prevents stale responses after edits.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  if (!validScenario(settings))
    return {
      view: null,
      message:
        "Capital: ₦100–₦10,000,000 · qualification: 1–30 · attempts: 1–10 · whole numbers only.",
    };
  return state.key === key
    ? state
    : { view: null, message: "Calculating scenario…" };
}
