import {
  render,
  screen,
  cleanup,
  within,
  fireEvent,
} from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { CalendarCoverage } from "./CalendarCoverage";
import { ResearchChart } from "./ResearchChart";
import type { Overview } from "./api";

afterEach(cleanup);
it("counts unique Lagos dates, distinguishes gaps and unknown timing, and filters leagues", () => {
  const league = {
    competition: "virtual-euro",
    dates: ["2026-10-18", "2026-10-20"],
    first_at: "2026-10-17T23:30:00+00:00",
    last_at: "2026-10-20T00:30:00+00:00",
    settled_fixtures: 16,
    unknown_timing_fixtures: 8,
  };
  const data = {
    calendar_coverage: {
      status: "available",
      leagues: [
        league,
        {
          ...league,
          competition: "virtual-football",
          dates: ["2026-10-19"],
          unknown_timing_fixtures: 0,
        },
      ],
    },
  } as Overview;
  const view = render(<CalendarCoverage data={data} league="all" />);
  expect(screen.getByText("3 calendar days with data")).toBeInTheDocument();
  expect(screen.getByText(/0 dates without timed/)).toHaveTextContent(
    "8 fixtures without timing",
  );
  view.rerender(<CalendarCoverage data={data} league="virtual-euro" />);
  expect(screen.getByText("2 calendar days with data")).toBeInTheDocument();
  expect(screen.getByText(/1 dates without timed/)).toBeInTheDocument();
  expect(screen.getByText(/18\/10\/2026, 00:30:00/)).toBeInTheDocument();
});
it("shows the selected balance in the graph and OHLC for candles", () => {
  render(
    <ResearchChart
      initial={5000000}
      shared
      runs={[
        {
          competition: "virtual-euro",
          season_id: "1",
          strategy_id: "a",
          team: "Test",
          status: "win",
          net_minor: 10000,
          total_staked_minor: 10000,
          gross_payout_minor: 20000,
          event_at: "2026-10-20T10:00:00Z",
        },
      ]}
    />,
  );
  expect(
    within(screen.getByLabelText("Selected chart balance")).getByText(
      /20 Oct, 11:00 WAT · ₦50,100.00/,
    ),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Candles" }));
  expect(
    within(screen.getByLabelText("Selected chart balance")).getByText(
      /O ₦50,000.00/,
    ),
  ).toHaveTextContent("C ₦50,100.00");
});
