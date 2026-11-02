import type { Overview } from "./api";
import type { ScenarioSettings, ScenarioView } from "./useScenario";
import "./simulator.css";

export function ScenarioControls({
  data,
  settings,
  change,
  view,
}: {
  data: Overview;
  settings: ScenarioSettings;
  change: (name: keyof ScenarioSettings, value: number | string) => void;
  view: ScenarioView | null;
}) {
  return (
    <section
      className="simulator-settings"
      aria-labelledby="simulation-settings-title"
    >
      <header className="settings-heading">
        <h2 id="simulation-settings-title">Simulation</h2>
        <span className="projection-badge">
          <span aria-hidden="true" />
          Retrospective research
        </span>
      </header>
      <div className="capital-row">
        <label className="capital-field">
          Initial capital
          <span className="capital-input">
            <span aria-hidden="true">₦</span>
            <input
              aria-label="Initial capital"
              type="number"
              min="100"
              max="10000000"
              step="1"
              value={settings.capital}
              onChange={(e) => change("capital", Number(e.target.value))}
            />
          </span>
        </label>
        <div className="mode-field">
          <span>Capital mode</span>
          <div
            className="settings-toggle"
            role="group"
            aria-label="Capital mode"
          >
            <button
              type="button"
              aria-pressed={
                (settings.capital_mode ?? "compound") === "compound"
              }
              onClick={() => change("capital_mode", "compound")}
            >
              Reinvest gains
            </button>
            <button
              type="button"
              aria-pressed={settings.capital_mode === "fixed"}
              onClick={() => change("capital_mode", "fixed")}
            >
              Fixed capital
            </button>
          </div>
        </div>
        <label className="target-field">
          Profit target · ₦
          <input
            aria-label="Stop at net profit"
            type="number"
            min="0"
            max="10000000"
            step="1"
            title="Net profit target; 0 means no target."
            value={settings.profit_stop ?? 0}
            onChange={(e) => change("profit_stop", Number(e.target.value))}
          />
          <small>0 = no target</small>
        </label>
      </div>
      <div className="simulator-controls">
        <div className="allowance-field">
          <span>Allowance</span>
          <div
            className="settings-toggle"
            role="group"
            aria-label="Allowance multiplier"
          >
            {[1, 2].map((n) => (
              <button
                type="button"
                key={n}
                aria-pressed={settings.allowance === n}
                onClick={() => change("allowance", n)}
              >
                ×{n}
              </button>
            ))}
          </div>
        </div>
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
      {view && (
        <footer className="settings-updated">
          Updated{" "}
          <time dateTime={view.source_as_of}>
            {new Date(view.source_as_of).toLocaleString("en-GB", {
              timeZone: "Africa/Lagos",
            })}{" "}
            WAT
          </time>
        </footer>
      )}
    </section>
  );
}
