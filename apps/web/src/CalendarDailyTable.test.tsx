import {
  render,
  screen,
  within,
  cleanup,
  fireEvent,
} from "@testing-library/react";
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

it("expands a day into a chronological run table and collapses without changing totals", () => {
  const detailed = {
    ...simulation,
    settlement_model: { seconds: 201, version: "test", basis: "modeled" },
    runs: [
      {
        ...run,
        id: "later",
        team: "Later team",
        season_id: "2",
        strategy_id: "champion-next-win",
        released_at: "2026-10-19T01:00:00Z",
      },
      {
        ...run,
        id: "earlier",
        team: "Earlier team",
        season_id: "1",
        strategy_id: "champion-next-season",
      },
      {
        ...run,
        id: "skipped",
        team: "Excluded team",
        status: "skipped_overlap",
      },
    ],
  };
  render(
    <CalendarDailyTable
      coverage={coverage}
      dates={coverage[0].dates}
      simulation={detailed}
      league="all"
    />,
  );
  const toggle = screen.getByRole("button", {
    name: "Inspect runs for 2026-10-19",
  });
  expect(toggle).toHaveAttribute("aria-expanded", "false");
  expect(
    screen.queryByRole("table", { name: "Run details for 2026-10-19" }),
  ).not.toBeInTheDocument();
  expect(
    screen.getByRole("button", { name: "Inspect runs for 2026-10-18" }),
  ).toBeDisabled();
  const total = screen.getByText("Total").closest("tr")!.textContent;
  fireEvent.click(toggle);
  expect(toggle).toHaveAttribute("aria-expanded", "true");
  const table = screen.getByRole("table", {
    name: "Run details for 2026-10-19",
  });
  const rows = within(table).getAllByRole("row").slice(1);
  expect(rows[0]).toHaveTextContent("Earlier team");
  expect(rows[1]).toHaveTextContent("Later team");
  expect(rows[0]).toHaveTextContent("19/10/2026, 00:30:00");
  expect(rows[0]).toHaveTextContent("18/10/2026, 22:00:00");
  expect(rows[0]).toHaveTextContent("Euro · Next Season · season 1");
  expect(within(table).queryByText("Excluded team")).not.toBeInTheDocument();
  expect(
    screen.getByText(/release times use the settlement model/),
  ).toBeInTheDocument();
  fireEvent.click(toggle);
  expect(
    screen.queryByRole("table", { name: "Run details for 2026-10-19" }),
  ).not.toBeInTheDocument();
  expect(screen.getByText("Total").closest("tr")!.textContent).toBe(total);
});
