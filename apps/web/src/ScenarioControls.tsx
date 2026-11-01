import type { Overview } from "./api";
import type { ScenarioSettings, ScenarioView } from "./useScenario";
import "./simulator.css";

export function ScenarioControls({
  data,
  settings,
  change,
  view,
  baseCapital,
  factor,
}: {
  data: Overview;
  settings: ScenarioSettings;
  change: (name: keyof ScenarioSettings, value: number | string) => void;
  view: ScenarioView | null;
  baseCapital: number;
  factor: number;
}) {
  return (
    <>
      <section className="simulator-settings" aria-labelledby="simulator-title">
        <div className="section-heading">
          <div>
            <p className="eyebrow">One capital across the workspace</p>
            <h1 id="simulator-title">Capital projection</h1>
          </div>
          <span className="mode-badge">Saved evidence · adjustable rules</span>
        </div>
        <p className="subtle">
          Change capital to scale every projected amount. Rule changes
          recalculate the base scenario.
        </p>
        <div className="simulator-controls">
          <label>
            Projected initial capital · ₦
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
            Capital mode
            <select
              aria-label="Capital mode"
              value={settings.capital_mode ?? "compound"}
              onChange={(e) => change("capital_mode", e.target.value)}
            >
              <option value="compound">Compound capital + gains</option>
              <option value="fixed">Fixed entered capital</option>
            </select>
          </label>
          <label>
            Base stop at net profit · ₦
            <input
              aria-label="Stop at net profit"
              type="number"
              min="0"
              max="10000000"
              step="1"
              value={settings.profit_stop ?? 0}
              onChange={(e) => change("profit_stop", Number(e.target.value))}
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
          subsequent trading window. Fixed mode sizes from entered capital;
          compound mode sizes from total funds. Gains remain in the displayed
          balance. A run is skipped if available funds cannot cover its
          allowance. Profit stop uses settled net profit; 0 means no target. A
          completed run may exceed the target. All monetary values are NGN.
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
      <p className="recovery-banner" role="note">
        Scaled projection · base ₦{baseCapital.toLocaleString("en-NG")} ·
        multiplier ×
        {factor.toLocaleString("en-NG", { maximumFractionDigits: 6 })}. All
        simulation money, including History, uses this factor. Odds, dates and
        run decisions stay fixed for the base scenario. Fractional stakes are
        proportional illustrations, not rounded executable bets. Recorded
        evidence and paper balances are unchanged. The profit-stop input is in
        base naira; its projected target scales by the same factor.
      </p>
    </>
  );
}
