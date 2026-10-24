import { naira, type RunCalculation as Calculation } from "./api";

export function RunCalculation({ calculation }: { calculation: Calculation }) {
  return (
    <section aria-label="Simulation stake and profit calculation">
      <p>
        <strong>Simulation: {calculation.status.replaceAll("_", " ")}</strong>
      </p>
      <p className="subtle">
        {calculation.mode === "event_order_simulation"
          ? "Event-order simulation"
          : "Independent run replay"}{" "}
        · starting capital {naira(calculation.starting_capital_minor)}. This
        calculation does not change the shared simulation balance.
      </p>
      {calculation.attempts.map((attempt) => (
        <p key={attempt.attempt}>
          Attempt {attempt.attempt}/4 · day {attempt.matchday} ·{" "}
          {attempt.home_team} vs {attempt.away_team} · odds {attempt.odds} ·
          stake {naira(attempt.stake_minor)} ·{" "}
          {attempt.status === "settled"
            ? `payout ${naira(attempt.payout_minor ?? 0)} · result ${attempt.result}`
            : `potential payout ${naira(attempt.potential_payout_minor)} · result pending`}
        </p>
      ))}
      <p>
        Total staked {naira(calculation.total_staked_minor)} · gross payout{" "}
        {naira(calculation.gross_payout_minor)} ·{" "}
        {calculation.net_minor !== null
          ? `net profit/loss ${naira(calculation.net_minor)}`
          : `realized profit/loss ${naira(calculation.realized_net_minor)} · run incomplete`}
      </p>
      {["awaiting_verified_odds", "awaiting_recorded_odds"].includes(
        calculation.status,
      ) && (
        <p className="subtle">
          No usable saved price was found for day{" "}
          {calculation.unpriced_matchday}. Pricing pauses here; later results
          are not assigned invented stakes or profit.
        </p>
      )}
      {calculation.data_quality_notes?.map((note) => (
        <p className="subtle" key={note}>
          Data quality: {note}
        </p>
      ))}
      {calculation.status === "market_blocked" && (
        <p className="subtle">
          Confirmed market block on day {calculation.unpriced_matchday}. No
          stake or payout is invented for that entry.
        </p>
      )}
    </section>
  );
}
