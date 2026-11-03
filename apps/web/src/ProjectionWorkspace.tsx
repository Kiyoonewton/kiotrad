import { useState } from "react";
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

export function ProjectionWorkspace({
  data,
  league,
  page,
}: {
  data: OverviewData;
  league: string;
  page: "Overview" | "Strategies" | "History";
}) {
  const [settings, setSettings] = useState<ScenarioSettings>(() =>
    initialScenario(data.shared_simulation!.starting_capital_minor / 100),
  );
  const { view, message } = useScenario(settings, league);
  const change = (name: keyof ScenarioSettings, value: number | string) =>
    setSettings((s) => ({ ...s, [name]: value }));
  const scenarioData = view ? { ...data, shared_simulation: view } : null;
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
      {view?.runs.some((r) => r.skip_reason === "calculation_range_limit") && (
        <p role="status">
          Calculation limit reached. Some runs could not be sized; inspect
          skipped runs.
        </p>
      )}
      {page === "Strategies" && (
        <StrategyList
          strategies={data.strategies}
          competitions={data.competitions}
          settings={settings}
          change={change}
          sharedRuns={
            view ? [...view.runs, ...(view.unqualified ?? [])] : undefined
          }
        />
      )}
      {view &&
        scenarioData &&
        (page === "Overview" ? (
          <Overview
            data={scenarioData}
            league={league}
            scopeLabel={`Retrospective research · ${league === "all" ? "all leagues" : (data.competitions.find((c) => c.id === league)?.name ?? league)} · ${settings.strategy === "all" ? "both strategies" : (data.strategies.find((s) => s.id === settings.strategy)?.name ?? settings.strategy)}`}
          />
        ) : page === "History" ? (
          <ProjectionHistory view={view} />
        ) : null)}
    </>
  );
}
