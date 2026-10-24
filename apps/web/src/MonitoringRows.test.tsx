import { cleanup, render, screen, within } from "@testing-library/react";
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
