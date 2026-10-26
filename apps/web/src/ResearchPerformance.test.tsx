import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { ResearchPerformance } from "./ResearchPerformance";
import type { Overview } from "./api";

afterEach(cleanup);
const run = {
  competition: "virtual-football",
  season_id: "200",
  strategy_id: "champion-next-win",
  team: "Synthetic",
  status: "win",
  net_minor: 10000,
  total_staked_minor: 20000,
  gross_payout_minor: 30000,
  event_at: "2026-10-20T10:00:00Z",
};
const data: NonNullable<Overview["research_simulation"]> = {
  status: "available",
  starting_capital_per_run_minor: 700000,
  runs: [
    run,
    { ...run, competition: "virtual-euro" },
    { ...run, status: "awaiting_recorded_odds", net_minor: null },
  ],
};

it("plots completed research results while keeping incomplete pricing out of net profit", () => {
  render(<ResearchPerformance data={data} league="all" />);
  expect(screen.getAllByText("₦200.00")[0]).toBeInTheDocument();
  expect(screen.getByText("2 completed runs")).toBeInTheDocument();
  expect(screen.getByText(/1 incomplete calculations/)).toBeInTheDocument();
  expect(
    screen.getByRole("img", { name: /Cumulative net of independent/ }),
  ).toBeInTheDocument();
  expect(screen.getByText(/not one shared bankroll/)).toBeInTheDocument();
});

it("filters research results by league without displaying a shared balance", () => {
  render(<ResearchPerformance data={data} league="virtual-euro" />);
  expect(screen.getAllByText("₦100.00")[0]).toBeInTheDocument();
  expect(screen.queryByText("Portfolio balance")).not.toBeInTheDocument();
});

it("offers candle and bar views while displaying the configured initial capital", async () => {
  const { fireEvent } = await import("@testing-library/react");
  render(
    <ResearchPerformance
      data={{ ...data, starting_capital_per_run_minor: 1400000 }}
      league="all"
    />,
  );
  expect(screen.getAllByText("₦14,000.00").length).toBeGreaterThan(0);
  fireEvent.click(screen.getByRole("button", { name: "Candles" }));
  expect(screen.getByRole("button", { name: "Candles" })).toHaveAttribute(
    "aria-pressed",
    "true",
  );
  expect(screen.getByText(/Daily open/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Bars" }));
  expect(
    screen.getByText(/Profit or loss per completed run/),
  ).toBeInTheDocument();
});
