import { naira, type RunCalculation as Calculation } from "./api";

export function RunCalculation({ calculation }: { calculation: Calculation }) {
  return (
    <section aria-label="Simulation stake and profit calculation">
      <p>
        <strong>Simulation: {calculation.status.replaceAll("_", " ")}</strong>
      </p>
      <p className="subtle">
        Independent run replay · starting capital{" "}
        {naira(calculation.starting_capital_minor)}. This calculation does not
        change the shared simulation balance.
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
      {calculation.status === "awaiting_verified_odds" && (
        <p className="subtle">
          No eligible pre-match price was found for day{" "}
          {calculation.unpriced_matchday}. Pricing pauses here; later results
          are not assigned invented stakes or profit.
        </p>
      )}
    </section>
  );
}
