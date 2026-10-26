import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { SharedCapitalPerformance } from "./SharedCapitalPerformance";
import type { SharedSimulation } from "./sharedSimulation";
afterEach(cleanup);
const data: SharedSimulation = {
  status: "available",
  starting_capital_minor: 5000000,
  balance_minor: 5096100,
  reserved_allowance_minor: 0,
  active_staked_minor: 0,
  active_run_id: null,
  seasons_assessed: 83,
  timing_basis: "recorded_result_confirmation_conservative",
  runs: [
    {
      id: "a",
      competition: "virtual-football",
      season_id: "100",
      strategy_id: "champion-next-win",
      team: "Synthetic",
      entry_at: "2026-10-20T10:00:00Z",
      released_at: "2026-10-20T10:02:00Z",
      status: "win",
      net_minor: 96100,
      total_staked_minor: 96100,
      gross_payout_minor: 192200,
      estimate: {
        allowance_minor: 4997200,
        plan: { total_minor: 2498600, profit_per_attempt_minor: 96100 },
      },
    },
    {
      id: "b",
      entry_at: "2026-10-20T10:01:00Z",
      status: "skipped_overlap",
      blocked_by: "a",
    },
    {
      id: "c",
      entry_at: "2026-10-20T10:03:00Z",
      status: "insufficient_fixture_history",
    },
  ],
};
it("plots only accepted shared results and shows proportional targets and completeness", () => {
  render(<SharedCapitalPerformance data={data} />);
  expect(screen.getAllByText("₦50,961.00").length).toBeGreaterThan(0);
  expect(screen.getByText("Current capital")).toBeInTheDocument();
  expect(screen.getByText(/Started with ₦50,000.00/)).toBeInTheDocument();
  expect(
    screen.getByRole("img", {
      name: /Shared simulation balance after each accepted run/,
    }),
  ).toBeInTheDocument();
  expect(screen.getByText("50.0%")).toBeInTheDocument();
  expect(
    screen.getByText(/1 completed · 1 unresolved · 1 clashes excluded/),
  ).toBeInTheDocument();
  expect(screen.getAllByText("₦961.00").length).toBeGreaterThan(0);
  expect(screen.queryByText(/not one shared bankroll/)).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Bars" }));
  expect(
    screen.getByRole("img", {
      name: /Shared simulation balance after each accepted run/,
    }),
  ).toBeInTheDocument();
});
it("does not replace failed shared calculations with independent totals", () => {
  render(
    <SharedCapitalPerformance data={{ ...data, status: "unavailable" }} />,
  );
  expect(
    screen.getByText(/independent results are not substituted/),
  ).toBeInTheDocument();
  expect(screen.queryByText("₦50,961.00")).not.toBeInTheDocument();
});

it("supports candle zoom and sliding through older accepted runs", () => {
  const runs = Array.from({ length: 12 }, (_, i) => ({
    ...data.runs[0],
    id: String(i),
    season_id: String(100 + i),
    released_at: `2026-10-${String(10 + i).padStart(2, "0")}T10:02:00Z`,
  }));
  render(<SharedCapitalPerformance data={{ ...data, runs }} />);
  fireEvent.click(screen.getByRole("button", { name: "Candles" }));
  fireEvent.click(screen.getByRole("button", { name: "Zoom chart in" }));
  const pan = screen.getByRole("slider", { name: "Pan chart history" });
  expect(Number(pan.getAttribute("max"))).toBeGreaterThan(0);
  fireEvent.change(pan, { target: { value: "0" } });
  expect(pan).toHaveValue("0");
  fireEvent.click(screen.getByRole("button", { name: "Fit history" }));
  expect(pan).toHaveAttribute("max", "0");
});

it("separates budget skips from missing history and shows the affected fixtures", () => {
  render(
    <SharedCapitalPerformance
      data={{
        ...data,
        runs: [
          data.runs[0],
          {
            id: "budget",
            entry_at: "2026-10-20T10:00:00Z",
            status: "skipped_budget",
            skip_reason: "insufficient_allowance",
          },
          {
            id: "history",
            entry_at: "2026-10-20T10:00:00Z",
            status: "insufficient_fixture_history",
            missing_historical_fixtures: [
              {
                matchday: 5,
                home_team: "Synthetic",
                away_team: "Opponent",
                outcome: "H",
              },
            ],
          },
        ],
      }}
    />,
  );
  expect(
    screen.getByText(/1 budget skips · not missing data/),
  ).toBeInTheDocument();
  expect(
    screen.getByText(/1 completed · 1 unresolved · 0 clashes excluded/),
  ).toBeInTheDocument();
  expect(
    screen.getByText(
      /D5 · Synthetic vs Opponent · H: earlier-season odds missing/,
    ),
  ).toBeInTheDocument();
});
