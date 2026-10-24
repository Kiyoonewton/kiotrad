import { PaperCapital } from "./PaperCapital";
import { PaperActivity } from "./PaperActivity";
import { naira } from "./api";
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
          <strong>
            {data.bankroll?.balance_minor != null
              ? naira(data.bankroll.balance_minor)
              : "₦ —"}
          </strong>
          <span>
            {data.bankroll?.status === "paper"
              ? "Simulated paper balance"
              : data.bankroll?.status === "unavailable"
                ? "Bankroll unavailable"
                : "Bankroll not configured"}
          </span>
          {data.bankroll?.status === "paper" && (
            <span>
              Available {naira(data.bankroll.available_minor ?? 0)} · Reserved{" "}
              {naira(data.bankroll.committed_minor ?? 0)}
            </span>
          )}
        </div>
      </section>
      <div className="performance-grid">
        <section className="chart-panel" aria-labelledby="capital-title">
          <div className="section-heading">
            <h2 id="capital-title">Capital performance</h2>
            <span className="subtle">Settled paper attempts</span>
          </div>
          <div className="empty-chart">
            <div className="chart-grid" aria-hidden="true" />
            <PaperCapital data={data} />
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
            <span>Next capital milestone</span>
            <strong>
              {(data.bankroll?.next_milestone_minor ??
                data.bankroll?.target_balance_minor) != null
                ? naira(
                    (data.bankroll?.next_milestone_minor ??
                      data.bankroll?.target_balance_minor)!,
                  )
                : "Not set"}
            </strong>
            <p>
              {data.bankroll?.milestone_step_minor != null
                ? `${data.bankroll.milestones_reached ?? 0} milestone(s) reached · ${naira(data.bankroll.milestone_step_minor)} increments`
                : "A target tracks progress."}
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
              {item.collection_gap && (
                <small>
                  Collection interrupted · expected update{" "}
                  {formatLagos(item.collection_gap.expected_at)} WAT
                </small>
              )}
              {!item.collection_gap && item.last_collection_gap && (
                <small>
                  Resumed {formatLagos(item.last_collection_gap.resumed_at)} WAT
                  · {item.last_collection_gap.pending_season_ids.length}{" "}
                  season(s) queued for repair. Missed entries are not replayed.
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
                  interrupted: "Collection interrupted",
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
          <p>
            {data.bankroll?.status === "paper"
              ? "Paper ledger configured · observations below are separate"
              : "Read-only monitoring · paper bankroll not configured"}
          </p>
          <p>
            Paper worker:{" "}
            {(
              {
                active: "recent check observed",
                not_observed: "no heartbeat recorded",
                not_recent: "no recent heartbeat",
                unavailable: "health unavailable",
                retrying: "retrying after a failure",
                clock_unknown: "clock needs checking",
              } as Record<string, string>
            )[data.paper_worker?.status ?? "not_observed"] ?? "unknown"}
            {data.paper_worker?.observed_at &&
              ` · ${formatLagos(data.paper_worker.observed_at)} WAT`}
            {data.paper_worker?.last_result &&
              ` · ${data.paper_worker.last_result.replaceAll("_", " ")}`}
          </p>
          <PaperActivity data={data} />
          {data.paper_journal_full && (
            <p>
              Paper journal capacity reached. New runs are paused; export and
              archival are required.
            </p>
          )}
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
