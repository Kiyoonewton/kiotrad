import type { Overview as OverviewData } from "./api";
import { MonitoringRows } from "./MonitoringRows";
import { StrategyList } from "./StrategyList";

function formatLagos(value: string): string {
  return new Intl.DateTimeFormat("en-NG", {
    timeZone: "Africa/Lagos",
    year: "numeric",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23",
  }).format(new Date(value));
}

export function Overview({
  data,
  league,
}: {
  data: OverviewData;
  league: string;
}) {
  const competitions = data.competitions.filter(
    (item) => league === "all" || item.id === league,
  );
  const monitoring = (data.strategy_monitoring ?? []).filter(
    (row) => league === "all" || row.competition === league,
  );
  return (
    <>
      <section className="balance-header" aria-labelledby="balance-title">
        <div>
          <p className="eyebrow">One bankroll. Every decision accounted for.</p>
          <h1 id="balance-title">
            A clearer view
            <br />
            of your edge<span>.</span>
          </h1>
        </div>
        <div className="balance-value">
          <p>Portfolio balance</p>
          <strong>₦ —</strong>
          <span>Bankroll not configured</span>
        </div>
      </section>
      <div className="performance-grid">
        <section className="chart-panel" aria-labelledby="capital-title">
          <div className="section-heading">
            <h2 id="capital-title">Capital performance</h2>
            <span className="subtle">Settled runs</span>
          </div>
          <div className="empty-chart">
            <div className="chart-grid" aria-hidden="true" />
            <div className="chart-message">
              <span className="chart-symbol" aria-hidden="true">
                ↗
              </span>
              <h3>Your story starts with the first run.</h3>
              <p>
                Settled paper trades will build this chart.
                <br />
                No simulated returns are displayed yet.
              </p>
            </div>
            <div className="chart-axis">
              <span>First run</span>
              <span>Most recent</span>
            </div>
          </div>
        </section>
        <aside className="goal-panel">
          <div className="pitch-art" aria-hidden="true">
            <div className="pitch-line" />
            <div className="pitch-circle" />
          </div>
          <p className="eyebrow">Observe. Test. Track.</p>
          <h2>
            Patience is
            <br />
            part of the plan.
          </h2>
          <div className="goal-bottom">
            <span>Capital goal</span>
            <strong>Not set</strong>
            <p>
              A target tracks progress.
              <br />
              It doesn’t predict a finish date.
            </p>
          </div>
        </aside>
      </div>
      <section className="league-strip" aria-label="League collection status">
        {competitions.map((item) => (
          <div className="league-item" key={item.id}>
            <span className="league-monogram" aria-hidden="true">
              {item.name.slice(0, 1)}
            </span>
            <div>
              <strong>{item.name}</strong>
              <small>
                Season {item.season_id ?? "—"} · Odds day {item.matchday ?? "—"}
              </small>
              {item.last_success_at && (
                <small>Observed {formatLagos(item.last_success_at)} WAT</small>
              )}
              {item.fixture_count != null && (
                <small>
                  {item.settled_count ?? 0}/{item.fixture_count} results
                  recorded
                </small>
              )}
              {!!item.unresolved_seasons?.length && (
                <small>
                  {item.unresolved_seasons.length} unresolved season(s)
                </small>
              )}
              {item.market_observation === "availability_unknown" && (
                <small>Availability unknown</small>
              )}
              {item.latest_champion && (
                <small>
                  Verified champion: {item.latest_champion.team_name} (season{" "}
                  {item.latest_champion.season_id})
                </small>
              )}
              {item.latest_champion && (
                <small>
                  Champion confirmed{" "}
                  {formatLagos(item.latest_champion.confirmed_at)} WAT
                </small>
              )}
            </div>
            <span className="feed-status">
              <i />
              {(
                {
                  fresh: "Fresh capture",
                  stale: "Stale capture",
                  retrying: "Retrying",
                  unavailable: "Storage unavailable",
                  not_connected: "Not connected",
                  scheduled: "Awaiting scheduled update",
                } as Record<string, string>
              )[item.feed_status] ?? "Unknown"}
            </span>
          </div>
        ))}
      </section>
      <div className="activity-grid">
        <section className="quiet-panel" aria-labelledby="monitoring-title">
          <p className="eyebrow">Current activity</p>
          <h2 id="monitoring-title">Strategy observations.</h2>
          <p>Read-only monitoring · paper execution disabled</p>
          <MonitoringRows
            rows={monitoring}
            strategies={data.strategies}
            competitions={data.competitions}
          />
        </section>
        <section className="quiet-panel">
          <p className="eyebrow">A measured timeline</p>
          <h2>Know when opportunities overlap.</h2>
          <p>
            Cross-league alignment needs verified event times. Collection
            timestamps will never stand in for kickoff.
          </p>
          <div
            className="timeline-placeholder"
            aria-label="Event timeline awaiting data"
          >
            {competitions.map((item) => (
              <div key={item.id}>
                <span>{item.name}</span>
                <i />
              </div>
            ))}
          </div>
        </section>
      </div>
      <StrategyList
        strategies={data.strategies}
        monitoring={monitoring}
        competitions={data.competitions}
      />
    </>
  );
}
