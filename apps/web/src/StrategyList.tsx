import { RunBrowser } from "./RunBrowser";
import type { SharedRun } from "./sharedSimulation";
import { MonitoringRows } from "./MonitoringRows";
import { naira, type Strategy, type Overview } from "./api";

export function StrategyList({
  strategies,
  monitoring = [],
  competitions = [],
  sharedRuns,
}: {
  strategies: Strategy[];
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
      {strategies.map((strategy, index) => (
        <details className="strategy-row" key={strategy.id}>
          <summary>
            <span className="strategy-number">0{index + 1}</span>
            <span className="strategy-name">
              {strategy.name}
              <small>
                {strategy.version} · {strategy.rules.max_attempts} attempts
                maximum
              </small>
            </span>
            <span className="research-status">Research</span>
            <span className="expand-icon" aria-hidden="true">
              +
            </span>
          </summary>
          <div className="strategy-description">
            <details className="strategy-rules">
              <summary>Rules &amp; assumptions</summary>
              <p>{strategy.description}</p>
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
                Recover prior losses + attempt number × profit target. Stop at
                the first win.
              </p>
              <ul>
                {strategy.unresolved.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </details>
            <h3>Current matches</h3>
            <MonitoringRows
              rows={monitoring.filter(
                (row) => row.strategy_id === strategy.id || !row.strategy_id,
              )}
              sharedRuns={sharedRuns}
              strategies={strategies}
              competitions={competitions}
            />
            {sharedRuns && (
              <RunBrowser
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
