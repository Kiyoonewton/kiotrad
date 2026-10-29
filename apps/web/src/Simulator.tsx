import { CalendarCoverage } from "./CalendarCoverage";
import { useState } from "react";
import type { Overview } from "./api";
import { SharedCapitalPerformance } from "./SharedCapitalPerformance";
import { RunEventTimeline } from "./RunEventTimeline";
import { sharedRunMap } from "./runMapData";
import {
  initialScenario,
  useScenario,
  type ScenarioSettings,
} from "./useScenario";
import "./simulator.css";

export function Simulator({
  data,
  league,
}: {
  data: Overview;
  league: string;
}) {
  const [settings, setSettings] = useState<ScenarioSettings>(() =>
    initialScenario(
      (data.shared_simulation?.starting_capital_minor ?? 5000000) / 100,
    ),
  );
  const { view, message } = useScenario(settings, league);
  const change = (name: keyof ScenarioSettings, value: number | string) =>
    setSettings((s) => ({ ...s, [name]: value }));
  const limited = view?.runs.some(
    (r) => r.skip_reason === "calculation_range_limit",
  );
  return (
    <>
      <CalendarCoverage data={data} league={league} />
      <section className="simulator-settings" aria-labelledby="simulator-title">
        <div className="section-heading">
          <div>
            <p className="eyebrow">Research sandbox</p>
            <h1 id="simulator-title">Simulator</h1>
          </div>
          <span className="mode-badge">Saved evidence · adjustable rules</span>
        </div>
        <p className="subtle">
          Change a setting to recalculate. These scenarios leave the monitored
          benchmark and recorded decisions unchanged.
        </p>
        <div className="simulator-controls">
          <label>
            Initial capital · ₦
            <input
              aria-label="Initial capital"
              type="number"
              min="100"
              max="10000000"
              step="1"
              value={settings.capital}
              onChange={(e) => change("capital", Number(e.target.value))}
            />
          </label>
          <label>
            First win day ≥
            <input
              aria-label="Qualification day"
              type="number"
              min="1"
              max="30"
              step="1"
              value={settings.qualification}
              onChange={(e) => change("qualification", Number(e.target.value))}
            />
          </label>
          <label>
            Attempts maximum
            <input
              aria-label="Maximum attempts"
              type="number"
              min="1"
              max="10"
              step="1"
              value={settings.attempts}
              onChange={(e) => change("attempts", Number(e.target.value))}
            />
          </label>
          <label>
            Allowance
            <select
              aria-label="Allowance multiplier"
              value={settings.allowance}
              onChange={(e) => change("allowance", Number(e.target.value))}
            >
              <option value="1">×1 estimated cost</option>
              <option value="2">×2 estimated cost</option>
            </select>
          </label>
          <label>
            Strategy
            <select
              aria-label="Simulation strategy"
              value={settings.strategy}
              onChange={(e) => change("strategy", e.target.value)}
            >
              <option value="all">Both strategies</option>
              {data.strategies.map((strategy) => (
                <option key={strategy.id} value={strategy.id}>
                  {strategy.name}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="subtle">
          Qualification is the first-win day threshold; attempts are the
          subsequent trading window. All monetary values are NGN.
        </p>
        {view && (
          <small>
            Evidence through{" "}
            {new Date(view.source_as_of).toLocaleString("en-GB", {
              timeZone: "Africa/Lagos",
            })}{" "}
            WAT ·{" "}
            {view.scenario_cache === "hit"
              ? "Cached scenario"
              : "Recalculated from prepared evidence"}
          </small>
        )}
      </section>
      {message && (
        <p className="message-panel" role="status">
          {message}
        </p>
      )}
      {view && (
        <>
          {limited && (
            <p role="status" className="recovery-banner">
              Calculation limit reached: entries above ₦10,000,000 available
              capital are excluded. The displayed result covers processed runs
              only.
            </p>
          )}
          <SharedCapitalPerformance
            data={view}
            scopeLabel={`One active run · ${league === "all" ? "all leagues" : (data.competitions.find((c) => c.id === league)?.name ?? league)} · ${settings.strategy === "all" ? "both strategies" : (data.strategies.find((s) => s.id === settings.strategy)?.name ?? settings.strategy)}`}
          />
          <RunEventTimeline
            data={sharedRunMap(view)}
            shared
            competitions={data.competitions.filter(
              (c) => league === "all" || c.id === league,
            )}
          />
        </>
      )}
    </>
  );
}
