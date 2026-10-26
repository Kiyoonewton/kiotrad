import type { Overview } from "./api";
export function LeaguePulse({
  competitions,
}: {
  competitions: Overview["competitions"];
}) {
  const format = (time: string) =>
    new Intl.DateTimeFormat("en-NG", {
      timeZone: "Africa/Lagos",
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    }).format(new Date(time));
  const statuses: Record<string, string> = {
    fresh: "Fresh capture",
    stale: "Stale capture",
    not_connected: "Not connected",
    scheduled: "Awaiting scheduled update",
    retrying: "Retrying",
    unavailable: "Storage unavailable",
    interrupted: "Collection interrupted",
  };
  return (
    <section className="league-pulse" aria-label="League collection status">
      {competitions.map((c) => {
        const total = c.fixture_count ?? 0,
          settled = c.settled_count ?? 0;
        return (
          <article key={c.id}>
            <header>
              <strong>{c.name}</strong>
              <span>{statuses[c.feed_status] ?? "Unknown"}</span>
              <span
                className={`pulse-dot ${c.feed_status === "fresh" ? "fresh" : ""}`}
                title={c.feed_status.replaceAll("_", " ")}
              />
            </header>
            <div className="league-progress-number">
              <strong>
                {total ? `${Math.round((settled / total) * 100)}%` : "—"}
              </strong>
              <small>results collected</small>
            </div>
            <div className="coverage-track">
              <i
                style={{
                  width: `${total ? Math.min(100, (settled / total) * 100) : 0}%`,
                }}
              />
            </div>
            <div className="league-mini">
              <span>
                {settled}/{total || "—"} results
              </span>
              <span>Day {c.matchday ?? "—"}</span>
              <span title="Unresolved seasons">
                {c.unresolved_seasons?.length ?? 0} gaps
              </span>
            </div>
            <details>
              <summary>
                Season {c.season_id ?? "—"} · Odds day {c.matchday ?? "—"}
              </summary>
              {c.last_success_at && (
                <p>Observed {format(c.last_success_at)} WAT</p>
              )}
              {c.latest_champion && (
                <>
                  <p>
                    Verified champion: {c.latest_champion.team_name} (season{" "}
                    {c.latest_champion.season_id})
                  </p>
                  <p>
                    Champion confirmed {format(c.latest_champion.confirmed_at)}{" "}
                    WAT
                  </p>
                </>
              )}
              {c.collection_gap && (
                <p>
                  Collection interrupted · expected update{" "}
                  {format(c.collection_gap.expected_at)} WAT
                </p>
              )}
              {c.last_collection_gap && (
                <p>
                  Resumed {format(c.last_collection_gap.resumed_at)} WAT ·{" "}
                  {c.last_collection_gap.pending_season_ids.length} season(s)
                  queued for repair. Recovered events are included in research
                  simulations.
                </p>
              )}
              <p>
                {settled}/{total || "—"} results recorded
              </p>
              {!!c.unresolved_seasons?.length && (
                <p>{c.unresolved_seasons.length} unresolved season(s)</p>
              )}
              {c.market_observation === "availability_unknown" && (
                <p>Availability unknown</p>
              )}
            </details>
          </article>
        );
      })}
    </section>
  );
}
