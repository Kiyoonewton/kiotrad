import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { SharedRunCalculation } from "./SharedRunCalculation";
afterEach(cleanup);
it("displays the shared frozen target and actual-price stake instead of a fixed hundred", () => {
  render(
    <SharedRunCalculation
      run={{
        id: "example",
        entry_at: "2026-10-20T10:00:00Z",
        status: "win",
        capital_at_entry_minor: 5000000,
        total_staked_minor: 549500,
        gross_payout_minor: 741825,
        net_minor: 192325,
        estimate: {
          allowance_minor: 4999800,
          allowance_multiplier: 1,
          plan: { total_minor: 4999800, profit_per_attempt_minor: 192300 },
        },
        attempts: [
          {
            attempt: 1,
            matchday: 5,
            home_team: "Synthetic",
            away_team: "Opponent",
            actual_odds_scaled: 13500,
            stake_minor: 549500,
            target_profit_minor: 192300,
            payout_minor: 741825,
          },
        ],
      }}
    />,
  );
  expect(screen.getByText("₦1,923.00")).toBeInTheDocument();
  expect(screen.getAllByText("₦5,495.00")).toHaveLength(2);
  expect(screen.getByText("₦1,923.25")).toBeInTheDocument();
  expect(screen.getByText(/allowance ×1:/)).toBeInTheDocument();
  expect(screen.getByText("1.35")).toBeInTheDocument();
  expect(screen.queryByText("₦100.10")).not.toBeInTheDocument();
});
it("does not describe an unfunded entry's estimate as reserved capital", () => {
  render(<SharedRunCalculation run={{ id: "missing", status: "unfunded_entry",
    entry_at: "2026-10-20T10:00:00Z", funded: false, entry_issue: "missing_actual_odds",
    estimate: { allowance_minor: 5000000, plan: { total_minor: 2500000, profit_per_attempt_minor: 10000 } },
  }} />);
  expect(screen.getByText(/No stake placed · no capital reserved/)).toBeInTheDocument();
  expect(screen.queryByText(/Frozen profit per attempt/)).not.toBeInTheDocument();
});
