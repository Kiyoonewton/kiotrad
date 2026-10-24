import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { RunCalculation } from "./RunCalculation";
import type { RunCalculation as Calculation } from "./api";

afterEach(cleanup);

const calculation: Calculation = {
  mode: "independent_run_replay",
  status: "win",
  starting_capital_minor: 700000,
  total_staked_minor: 20000,
  gross_payout_minor: 30000,
  realized_net_minor: 10000,
  net_minor: 10000,
  attempts: [{
    attempt: 1, matchday: 5, home_team: "Synthetic", away_team: "Example",
    stake_minor: 20000, odds: "1.5", status: "settled", result: "H",
    potential_payout_minor: 30000, payout_minor: 30000,
    scheduled_start_at: "2026-10-24T10:01:00Z",
  }],
};

it("shows the simulation stake, gross payout and net separately", () => {
  render(<RunCalculation calculation={calculation} />);
  expect(screen.getByText(/odds 1.5/)).toHaveTextContent(/stake.*200\.00.*payout.*300\.00/);
  expect(screen.getByText(/Total staked/)).toHaveTextContent(/net profit\/loss.*100\.00/);
  expect(screen.getByText(/Independent run replay/)).toBeInTheDocument();
});

it("does not present an unpriced observed win as calculated profit", () => {
  render(<RunCalculation calculation={{ ...calculation, status: "awaiting_verified_odds", attempts: [],
    total_staked_minor: 0, gross_payout_minor: 0, realized_net_minor: 0, net_minor: null, unpriced_matchday: 5 }} />);
  expect(screen.getByText(/No eligible pre-match price/)).toBeInTheDocument();
  expect(screen.getByText(/Total staked/)).toHaveTextContent("run incomplete");
  expect(screen.queryByText(/net profit\/loss/)).not.toBeInTheDocument();
});
