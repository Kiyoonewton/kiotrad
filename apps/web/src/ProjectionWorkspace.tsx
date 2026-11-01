import { useMemo, useState } from "react";
import type { Overview as OverviewData } from "./api";
import { Overview } from "./Overview";
import { StrategyList } from "./StrategyList";
import { ScenarioControls } from "./ScenarioControls";
import { ProjectionHistory } from "./ProjectionHistory";
import {
  initialScenario,
  useScenario,
  validScenario,
  type ScenarioSettings,
} from "./useScenario";
import { projectMoney } from "./capitalProjection";

export function ProjectionWorkspace({
  data,
  league,
  page,
}: {
  data: OverviewData;
  league: string;
  page: "Overview" | "Strategies" | "History";
}) {
  // Pin the base for this workspace session; background refreshes never change its factor.
  const [baseCapital] = useState(
    () => data.shared_simulation!.starting_capital_minor / 100,
  );
  const [settings, setSettings] = useState<ScenarioSettings>(() =>
    initialScenario(baseCapital),
  );
  const factor = settings.capital / baseCapital;
  const baseSettings = { ...settings, capital: baseCapital };
  const { view: baseView, message } = useScenario(
    baseSettings,
    league,
    settings,
  );
  const view = useMemo(
    () =>
      baseView && validScenario(settings)
        ? projectMoney(baseView, factor)
        : null,
    [baseView, factor, settings],
  );
  const change = (name: keyof ScenarioSettings, value: number | string) =>
    setSettings((s) => ({ ...s, [name]: value }));
  const projected = view
    ? {
        ...data,
        shared_simulation: view,
        strategies: projectMoney(data.strategies, factor),
      }
    : null;
  if (
    !Number.isFinite(baseCapital) ||
    baseCapital < 100 ||
    baseCapital > 10000000
  ) {
    return (
      <p role="status">
        Base capital unavailable. Waiting for a valid dashboard baseline; no
        projected amounts are shown.
      </p>
    );
  }
  return (
    <>
      <ScenarioControls
        data={data}
        settings={settings}
        change={change}
        view={view}
      />
      {!validScenario(settings) ? (
        <p role="status">
          Capital: ₦100–₦10,000,000 · qualification: 1–30 · attempts: 1–10 ·
          whole numbers only.
        </p>
      ) : (
        message && (
          <p className="message-panel" role="status">
            {message}
          </p>
        )
      )}
      {baseView?.runs.some(
        (r) => r.skip_reason === "calculation_range_limit",
      ) && (
        <p role="status">
          Base calculation limit reached. This projection scales the processed
          base runs only; increasing projected capital does not remove base
          exclusions.
        </p>
      )}
      {view &&
        projected &&
        (page === "Overview" ? (
          <Overview
            data={projected}
            league={league}
            scopeLabel={`Projected results · ${league === "all" ? "all leagues" : (data.competitions.find((c) => c.id === league)?.name ?? league)} · ${settings.strategy === "all" ? "both strategies" : (data.strategies.find((s) => s.id === settings.strategy)?.name ?? settings.strategy)}`}
          />
        ) : page === "History" ? (
          <ProjectionHistory view={view} />
        ) : (
          <StrategyList
            strategies={projected.strategies}
            competitions={data.competitions}
            sharedRuns={view.runs}
            monitoring={data.strategy_monitoring?.filter(
              (r) => league === "all" || r.competition === league,
            )}
          />
        ))}
    </>
  );
}
