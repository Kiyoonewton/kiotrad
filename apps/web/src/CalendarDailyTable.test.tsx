import { render, screen, within, cleanup } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { CalendarDailyTable } from "./CalendarDailyTable";
import type { SharedSimulation, SharedRun } from "./sharedSimulation";
afterEach(cleanup);
const run: SharedRun = {
  id: "a",
  competition: "virtual-euro",
  entry_at: "2026-10-18T21:00:00Z",
  released_at: "2026-10-18T23:30:00Z",
  status: "win",
  funded: true,
  net_minor: 10000,
  total_staked_minor: 20000,
  gross_payout_minor: 30000,
  estimate: {
    allowance_minor: 30000,
    plan: { total_minor: 30000, profit_per_attempt_minor: 10000 },
  },
};
const simulation = {
  status: "available",
  runs: [
    run,
    {
      ...run,
      id: "b",
      net_minor: -10000,
      total_staked_minor: 10000,
      gross_payout_minor: 0,
      status: "four_losses",
    },
    { ...run, id: "c", status: "skipped_overlap" },
    { ...run, id: "d", funded: false },
    { ...run, id: "e", net_minor: null },
  ],
} as SharedSimulation;
const coverage = [
  {
    competition: "virtual-euro",
    dates: ["2026-10-18", "2026-10-19"],
    first_at: null,
    last_at: null,
    settled_fixtures: 8,
    unknown_timing_fixtures: 0,
    days: [
      {
        date: "2026-10-19",
        first_at: "2026-10-18T23:10:00Z",
        last_at: "2026-10-19T04:00:00Z",
      },
    ],
  },
];
it("books entire completed runs on their Lagos release date and excludes skipped/unfunded/pending", () => {
  render(
    <CalendarDailyTable
      coverage={coverage}
      dates={coverage[0].dates}
      simulation={simulation}
      league="all"
    />,
  );
  const row = screen.getByText("2026-10-19").closest("tr")!;
  expect(row).toHaveTextContent("2 settled runs");
  expect(row).toHaveTextContent("00:10:00 – 05:00:00");
  expect(
    within(row)
      .getAllByRole("cell")
      .slice(2)
      .map((c) => c.textContent),
  ).toEqual(["₦0.00", "₦300.00", "₦300.00"]);
  expect(screen.getByText("2026-10-18").closest("tr")).toHaveTextContent(
    "0 settled runs",
  );
});
it("filters financial totals by league and does not fabricate zero totals while unavailable", () => {
  const view = render(
    <CalendarDailyTable
      coverage={coverage}
      dates={coverage[0].dates}
      simulation={simulation}
      league="virtual-football"
    />,
  );
  expect(screen.queryByText("2 settled runs")).not.toBeInTheDocument();
  view.rerender(
    <CalendarDailyTable
      coverage={coverage}
      dates={coverage[0].dates}
      simulation={null}
      league="all"
    />,
  );
  expect(screen.getByRole("status")).toHaveTextContent("unavailable");
  expect(screen.queryByText("₦0.00")).not.toBeInTheDocument();
});
