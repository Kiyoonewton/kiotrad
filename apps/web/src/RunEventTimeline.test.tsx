import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { RunEventTimeline } from "./RunEventTimeline";
import { MonitoringGroups } from "./MonitoringGroups";
import type { Overview } from "./api";

afterEach(cleanup);
const competitions = [
  { id: "virtual-football", name: "English" },
] as Overview["competitions"];
const run = {
  competition: "virtual-football",
  season_id: "200",
  strategy_id: "champion-next-win",
  team: "Synthetic",
  status: "win",
  net_minor: 10000,
  total_staked_minor: 20000,
  gross_payout_minor: 30000,
  started_at: "2026-10-20T10:00:00Z",
  event_at: "2026-10-20T10:12:00Z",
  attempts: 4,
};
it("zooms scheduled spans, pans by keyboard and inspects individual runs", () => {
  render(
    <RunEventTimeline
      data={{ status: "available", runs: [run] }}
      competitions={competitions}
    />,
  );
  const svg = screen.getByRole("img", { name: /Scheduled run spans/ });
  expect(svg).toHaveAttribute("width", "1100");
  fireEvent.change(screen.getByRole("slider", { name: "Run timeline zoom" }), {
    target: { value: "3" },
  });
  expect(svg).toHaveAttribute("width", "8800");
  const region = screen.getByRole("region", {
    name: "Scrollable run timeline",
  });
  fireEvent.keyDown(region, { key: "ArrowRight" });
  expect(region.scrollLeft).toBe(180);
  fireEvent.keyDown(
    screen.getByRole("button", { name: /English Next Win Synthetic/ }),
    { key: "Enter" },
  );
  expect(screen.getByText(/Season 200 · 4 attempts/)).toBeInTheDocument();
  expect(screen.getByText(/₦100.00 net/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Fit all" }));
  expect(svg).toHaveAttribute("width", "1100");
  expect(region.scrollLeft).toBe(0);
});
it("uses ticks for unknown starts and excludes unpriced results", () => {
  render(
    <RunEventTimeline
      data={{
        status: "available",
        runs: [
          { ...run, started_at: null },
          { ...run, season_id: "201", net_minor: null },
        ],
      }}
      competitions={competitions}
    />,
  );
  const entries = screen.getAllByRole("button", { name: /English Next Win/ });
  expect(entries).toHaveLength(1);
  const line = entries[0].querySelector("line")!;
  expect(line.getAttribute("x1")).toBe(line.getAttribute("x2"));
});
it("groups cards into separate strategy rows", () => {
  const strategies = [
    { id: "champion-next-win", name: "Champion — Next Win" },
    { id: "champion-next-season", name: "Champion — Next Season" },
  ] as Overview["strategies"];
  render(
    <MonitoringGroups
      competitions={competitions}
      strategies={strategies}
      rows={[
        {
          competition: "virtual-football",
          strategy_id: "champion-next-season",
          status: "not_qualified",
          team: "Second",
        },
        {
          competition: "virtual-football",
          strategy_id: "champion-next-win",
          status: "not_qualified",
          team: "First",
        },
      ]}
    />,
  );
  const groups = screen.getAllByRole("region");
  expect(groups[0]).toHaveAccessibleName("Champion — Next Win");
  expect(groups[0]).toHaveTextContent("First");
  expect(groups[1]).toHaveAccessibleName("Champion — Next Season");
  expect(groups[1]).toHaveTextContent("Second");
});
