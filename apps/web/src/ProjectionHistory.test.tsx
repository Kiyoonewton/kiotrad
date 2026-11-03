import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { ProjectionHistory } from "./ProjectionHistory";
import type { SharedSimulation } from "./sharedSimulation";
afterEach(cleanup);

it("pages scenario seasons and filters by Lagos entry date without mixing in recorded history", () => {
  const runs = Array.from({ length: 12 }, (_, i) => ({
    id: String(i),
    season_id: String(100 + i),
    competition: "virtual-euro",
    strategy_id: "champion-next-win",
    team: "Example",
    status: "win",
    entry_at: `2026-10-${String(i + 1).padStart(2, "0")}T23:30:00Z`,
    net_minor: 5000,
    total_staked_minor: 10000,
    gross_payout_minor: 15000,
  }));
  render(<ProjectionHistory view={{ runs } as SharedSimulation} />);
  expect(screen.getByText("euro / 111")).toBeInTheDocument();
  expect(screen.queryByText("euro / 100")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  expect(screen.getByText("euro / 100")).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("From · WAT"), {
    target: { value: "2026-10-02" },
  });
  fireEvent.change(screen.getByLabelText("To · WAT"), {
    target: { value: "2026-10-02" },
  });
  expect(screen.getByText("euro / 100")).toBeInTheDocument();
  expect(screen.queryByText("euro / 101")).not.toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Next" })).toBeDisabled();
  fireEvent.change(screen.getByLabelText("From · WAT"), {
    target: { value: "2026-11-01" },
  });
  expect(
    screen.getByText("No scenario runs for this selection."),
  ).toBeInTheDocument();
});
it("combines filters, resets them and excludes skipped previews from settled totals", () => {
  const base = {
    competition: "virtual-euro",
    strategy_id: "champion-next-win",
    entry_at: "2026-10-20T23:30:00Z",
    released_at: "2026-10-21T00:00:00Z",
    net_minor: 5000,
    total_staked_minor: 10000,
    gross_payout_minor: 15000,
  };
  render(
    <ProjectionHistory
      view={
        {
          runs: [
            {
              ...base,
              id: "a",
              season_id: "100",
              team: "Berlin",
              status: "win",
            },
            {
              ...base,
              id: "b",
              season_id: "101",
              team: "London",
              status: "skipped_overlap",
              net_minor: 900000,
            },
            {
              ...base,
              id: "c",
              season_id: "102",
              team: "Madrid",
              strategy_id: "champion-next-season",
              status: "four_losses",
              net_minor: -10000,
              gross_payout_minor: 0,
            },
          ],
        } as SharedSimulation
      }
    />,
  );
  expect(screen.getByLabelText("Filtered settled totals")).toHaveTextContent(
    "-₦50.00",
  );
  fireEvent.change(screen.getByLabelText("Result"), {
    target: { value: "skipped" },
  });
  expect(screen.getByText("London")).toBeInTheDocument();
  expect(screen.queryByText("Berlin")).not.toBeInTheDocument();
  expect(screen.getByLabelText("Filtered settled totals")).toHaveTextContent(
    "₦0.00",
  );
  fireEvent.click(screen.getByRole("button", { name: "Reset filters" }));
  fireEvent.change(screen.getByLabelText("Strategy"), {
    target: { value: "champion-next-season" },
  });
  fireEvent.change(screen.getByLabelText("Team or season"), {
    target: { value: "Madrid" },
  });
  expect(screen.getByText("Madrid")).toBeInTheDocument();
  expect(screen.queryByText("Berlin")).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("Result"), {
    target: { value: "win" },
  });
  expect(screen.getByRole("status")).toHaveTextContent("No scenario runs");
});
