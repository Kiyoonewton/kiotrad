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
  fireEvent.change(screen.getByLabelText("Run entry date (Lagos)"), {
    target: { value: "2026-10-02" },
  });
  expect(screen.getByText("euro / 100")).toBeInTheDocument();
  expect(screen.queryByText("euro / 101")).not.toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Next" })).toBeDisabled();
  fireEvent.change(screen.getByLabelText("Run entry date (Lagos)"), {
    target: { value: "2026-11-01" },
  });
  expect(
    screen.getByText("No scenario runs for this selection."),
  ).toBeInTheDocument();
});
