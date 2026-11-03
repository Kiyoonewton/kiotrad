import { StrategyTotals } from "./StrategyTotals";
import { settledStrategyNet } from "./strategyNet";
import { StrategyParameters } from "./StrategyParameters";
import type { ScenarioSettings } from "./useScenario";
import { RunBrowser } from "./RunBrowser";
import type { SharedRun } from "./sharedSimulation";
import { MonitoringRows } from "./MonitoringRows";
import { naira, type Strategy, type Overview } from "./api";

export function StrategyList({
  strategies,
  monitoring = [],
  competitions = [],
  sharedRuns,
  settings,
  change,
}: {
  strategies: Strategy[];
  settings?: ScenarioSettings;
  change?: (name: keyof ScenarioSettings, value: number | string) => void;
  sharedRuns?: SharedRun[];
  monitoring?: Overview["strategy_monitoring"];
  competitions?: Overview["competitions"];
}) {
  return (
    <section className="strategy-section" aria-labelledby="strategy-title">
      <div className="section-heading">
        <div>
          <p className="eyebrow">Strategy library</p>
          <h2 id="strategy-title">Strategies</h2>
        </div>
        <span className="subtle">{strategies.length} strategies</span>
      </div>
      {settings && change && (
        <section className="strategy-settings" aria-label="Strategy settings">
          <h3>Strategy settings</h3>
          <div className="strategy-settings-grid">
            {strategies.map((strategy) => (
              <fieldset key={strategy.id}>
                <legend>{strategy.name}</legend>
                <StrategyParameters
                  id={strategy.id}
                  settings={settings}
                  change={change}
                />
              </fieldset>
            ))}
          </div>
        </section>
      )}
      {strategies.map((strategy, index) => (
        <details className="strategy-row" key={strategy.id}>
          <summary>
            <span className="strategy-number">0{index + 1}</span>
            <span className="strategy-name">
              {strategy.name}
              <small>
                {settings ? "Payout carry v1" : strategy.version} ·{" "}
                {settings
                  ? ((strategy.id === "champion-next-win"
                      ? settings.next_win_attempts
                      : settings.next_season_attempts) ?? settings.attempts)
                  : strategy.rules.max_attempts}{" "}
                attempts maximum
              </small>
            </span>
            <span className="strategy-net">
              <small>Settled net</small>
              <strong>
                {sharedRuns
                  ? naira(
                      settledStrategyNet(
                        sharedRuns.filter(
                          (run) => run.strategy_id === strategy.id,
                        ),
                      ),
                    )
                  : "Calculating…"}
              </strong>
            </span>
            <span className="expand-icon" aria-hidden="true">
              +
            </span>
          </summary>
          <div className="strategy-description">
            <details className="strategy-rules">
              <summary>Rules &amp; assumptions</summary>
              <p>
                {settings
                  ? `First win day ≥ ${(strategy.id === "champion-next-win" ? settings.next_win_qualification : settings.next_season_qualification) ?? settings.qualification}; up to ${(strategy.id === "champion-next-win" ? settings.next_win_attempts : settings.next_season_attempts) ?? settings.attempts} attempts.`
                  : strategy.description}
              </p>
              <p>
                <strong>Entry:</strong> {strategy.entry}
              </p>
              <div className="rule-strip">
                <span>
                  Minimum stake{" "}
                  <b>{naira(strategy.rules.minimum_stake_minor)}</b>
                </span>
                <span>
                  Profit / attempt{" "}
                  <b>
                    {sharedRuns
                      ? "Frozen per run"
                      : naira(strategy.rules.profit_per_attempt_minor)}
                  </b>
                </span>
              </div>
              <p>
                {settings
                  ? "Next stake = (previous potential payout + frozen profit target) ÷ (estimated odds − 1). Full-window recorded odds size the run; retrospective research only. Stop at the first win."
                  : "Recover prior losses + attempt number × profit target. Stop at the first win."}
              </p>
              <ul>
                {strategy.unresolved.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </details>
            {!settings && <h3>Current matches</h3>}
            {!settings && (
              <MonitoringRows
                rows={monitoring.filter(
                  (row) => row.strategy_id === strategy.id || !row.strategy_id,
                )}
                sharedRuns={sharedRuns}
                strategies={strategies}
                competitions={competitions}
              />
            )}
            {sharedRuns && (
              <StrategyTotals
                runs={sharedRuns.filter(
                  (run) => run.strategy_id === strategy.id,
                )}
              />
            )}
            {sharedRuns && (
              <RunBrowser
                qualifiedOnly
                runs={sharedRuns.filter(
                  (run) => run.strategy_id === strategy.id,
                )}
              />
            )}
          </div>
        </details>
      ))}
    </section>
  );
}
