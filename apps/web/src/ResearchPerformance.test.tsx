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
  expect(screen.getByText(/Open \/ high/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Bars" }));
  expect(screen.getByText(/Reference value at each date/)).toBeInTheDocument();
});
it("shows per-run payout and stake ranges instead of aggregate cash turnover", () => {
  render(
    <ResearchPerformance
      data={{
        ...data,
        runs: [
          run,
          {
            ...run,
            season_id: "201",
            net_minor: -20000,
            total_staked_minor: 20000,
            gross_payout_minor: 0,
          },
        ],
      }}
      league="all"
    />,
  );
  expect(screen.queryByText("Settled stakes")).not.toBeInTheDocument();
  expect(screen.queryByText("Gross payouts")).not.toBeInTheDocument();
  expect(
    screen.getByText("Run payout · low / high").parentElement,
  ).toHaveTextContent("₦0.00 / ₦300.00");
  expect(
    screen.getByText("Run stake-out · low / high").parentElement,
  ).toHaveTextContent("₦200.00 / ₦200.00");
});
it("bars encode the reference capital remaining, not just the event profit", async () => {
  const { fireEvent } = await import("@testing-library/react");
  const view = render(
    <ResearchPerformance
      data={{ ...data, runs: [run], starting_capital_per_run_minor: 5000000 }}
      league="all"
    />,
  );
  fireEvent.click(screen.getByRole("button", { name: "Bars" }));
  expect(
    view.container.querySelector('rect[data-reference-minor="5010000"]'),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Candles" }));
  fireEvent.change(screen.getByRole("combobox", { name: "Candle interval" }), {
    target: { value: "1" },
  });
  expect(screen.getByRole("combobox", { name: "Candle interval" })).toHaveValue(
    "1",
  );
  expect(
    screen.getByRole("slider", { name: "Visible chart nodes" }),
  ).toBeInTheDocument();
});
