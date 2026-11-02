import {
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { MonitoringRows } from "./MonitoringRows";
afterEach(cleanup);
it("shows an observed win without implying priced profit, and keeps inactive windows quiet", () => {
  render(
    <MonitoringRows
      strategies={[]}
      competitions={[]}
      rows={[
        {
          competition: "English",
          status: "observed_win",
          team: "Example champion",
          attempt: 2,
          simulation: {
            mode: "event_order_simulation",
            status: "awaiting_recorded_odds",
            starting_capital_minor: 700000,
            total_staked_minor: 0,
            gross_payout_minor: 0,
            realized_net_minor: 0,
            net_minor: null,
            attempts: [],
            unpriced_matchday: 5,
          },
        },
        {
          competition: "Euro",
          status: "not_qualified",
          team: "Other champion",
        },
      ]}
    />,
  );
  expect(screen.getByText("✓ Win observed")).toBeInTheDocument();
  const track = screen.getByLabelText("Observed window: 2 of 4 attempts");
  expect(within(track).getByText("Win")).toBeInTheDocument();
  expect(screen.getAllByText("—").length).toBeGreaterThanOrEqual(3);
  expect(
    screen.queryByLabelText("Observed window: 0 of 4 attempts"),
  ).not.toBeInTheDocument();
  expect(
    screen.getAllByText("Qualification details", { selector: "summary" })[0]
      .parentElement,
  ).not.toHaveAttribute("open");
});

it("filters qualification from the recorded decision and restores all observations", () => {
  const rows = [
    {
      competition: "Euro",
      season_id: "100",
      status: "observed_win",
      team: "Qualified team",
      trigger_day: 5,
    },
    {
      competition: "Euro",
      season_id: "101",
      status: "not_qualified",
      team: "Early winner",
      trigger_day: 2,
    },
    {
      competition: "Euro",
      season_id: "102",
      status: "waiting_for_history",
      team: "Unverified team",
    },
  ];
  const before = JSON.stringify(rows);
  render(<MonitoringRows rows={rows} strategies={[]} competitions={[]} />);
  fireEvent.click(screen.getByRole("button", { name: "Qualified (1)" }));
  expect(screen.getByText("Qualified team")).toBeInTheDocument();
  expect(screen.queryByText("Early winner")).not.toBeInTheDocument();
  expect(screen.queryByText("Unverified team")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "All (3)" }));
  expect(screen.getByText("Early winner")).toBeInTheDocument();
  expect(screen.getByText("Unverified team")).toBeInTheDocument();
  expect(JSON.stringify(rows)).toBe(before);
});
