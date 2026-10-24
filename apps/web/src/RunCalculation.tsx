import { naira, type RunCalculation as Calculation } from "./api";

export function RunCalculation({
  calculation: c,
}: {
  calculation: Calculation;
}) {
  const unpriced = [
    "awaiting_verified_odds",
    "awaiting_recorded_odds",
  ].includes(c.status);
  const noPrices = unpriced && c.attempts.length === 0;
  return (
    <section
      className="run-financials"
      aria-label="Simulation stake and profit calculation"
    >
      <div className="pricing-status">
        {unpriced
          ? "◷ Price missing"
          : c.status === "win"
            ? "✓ Priced win"
            : c.status.replaceAll("_", " ")}
      </div>
      <dl className="run-metrics">
        <div>
          <dt>Staked</dt>
          <dd>{noPrices ? "—" : naira(c.total_staked_minor)}</dd>
        </div>
        <div>
          <dt>Payout</dt>
          <dd>{noPrices ? "—" : naira(c.gross_payout_minor)}</dd>
        </div>
        <div>
          <dt>Net</dt>
          <dd
            className={
              c.net_minor !== null && c.net_minor > 0 ? "positive-net" : ""
            }
          >
            {c.net_minor === null ? "—" : naira(c.net_minor)}
          </dd>
        </div>
      </dl>
      {unpriced && (
        <small className="price-note">
          Day {c.unpriced_matchday} odds needed · profit pending
        </small>
      )}
      <details className="run-details">
        <summary>Calculation details</summary>
        <p className="subtle">
          {c.mode === "event_order_simulation"
            ? "Event-order simulation"
            : "Independent run replay"}{" "}
          · starting capital {naira(c.starting_capital_minor)}. Independent of
          the shared balance.
        </p>
        <div className="paper-table-scroll">
          <table className="attempt-table">
            <thead>
              <tr>
                <th>Day</th>
                <th>Fixture</th>
                <th>Odds</th>
                <th>Stake</th>
                <th>Payout</th>
              </tr>
            </thead>
            <tbody>
              {c.attempts.map((a) => (
                <tr key={a.attempt}>
                  <td>{a.matchday}</td>
                  <td>
                    {a.home_team} vs {a.away_team}
                  </td>
                  <td>{a.odds}</td>
                  <td>{naira(a.stake_minor)}</td>
                  <td>
                    {a.status === "settled"
                      ? naira(a.payout_minor ?? 0)
                      : "Pending"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {c.net_minor === null && (
          <p>Run incomplete · realized net {naira(c.realized_net_minor)}.</p>
        )}
        {unpriced && (
          <p>
            No usable saved price for day {c.unpriced_matchday}. Calculation
            awaits recorded odds.
          </p>
        )}
        {c.status === "market_blocked" && (
          <p>Confirmed market block on day {c.unpriced_matchday}.</p>
        )}
        {c.data_quality_notes?.map((note) => (
          <p key={note}>{note}</p>
        ))}
      </details>
    </section>
  );
}
