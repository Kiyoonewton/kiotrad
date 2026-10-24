import { naira, type Strategy } from "./api";

export function StrategyList({ strategies }: { strategies: Strategy[] }) {
  return (
    <section className="strategy-section" aria-labelledby="strategy-title">
      <div className="section-heading">
        <div>
          <p className="eyebrow">A growing research library</p>
          <h2 id="strategy-title">Strategies, with a reason.</h2>
        </div>
        <span className="subtle">{strategies.length} draft definitions</span>
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
            <p>{strategy.description}</p>
            <p>
              <strong>Entry:</strong> {strategy.entry}
            </p>
            <div className="rule-strip">
              <span>
                Minimum stake <b>{naira(strategy.rules.minimum_stake_minor)}</b>
              </span>
              <span>
                Profit per attempt{" "}
                <b>{naira(strategy.rules.profit_per_attempt_minor)}</b>
              </span>
              <span>
                Monitoring{" "}
                <b>
                  {strategy.monitoring_enabled ? "Enabled" : "Not connected"}
                </b>
              </span>
            </div>
            <p className="subtle">
              Recovery target: prior losses + attempt number × profit per
              attempt. Stop at the first win.
            </p>
            <h3>Before live testing</h3>
            <ul>
              {strategy.unresolved.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
        </details>
      ))}
    </section>
  );
}
