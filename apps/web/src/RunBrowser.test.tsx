import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { RunBrowser } from "./RunBrowser";
import type { SharedRun } from "./sharedSimulation";
afterEach(cleanup);
it("keeps skipped and unpriced runs inspectable and filters without changing evidence", () => {
  const runs: SharedRun[] = [
    {
      id: "win",
      team: "Synthetic winner",
      season_id: "100",
      status: "win",
      entry_at: "2026-10-20T10:00:00Z",
      released_at: "2026-10-20T10:03:21Z",
      net_minor: 10000,
    },
    {
      id: "skip",
      team: "Synthetic skipped",
      season_id: "101",
      status: "skipped_overlap",
      entry_at: "",
      blocked_by: "win",
    },
    {
      id: "missing",
      team: "Synthetic unpriced",
      season_id: "102",
      status: "unfunded_entry",
      entry_at: "",
      funded: false,
      entry_issue: "missing_actual_odds",
    },
  ];
  const before = JSON.stringify(runs);
  render(<RunBrowser runs={runs} />);
  expect(screen.getByText("Synthetic unpriced")).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Settled" }));
  expect(screen.getByText("Synthetic winner")).toBeInTheDocument();
  expect(screen.queryByText("Synthetic skipped")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Skipped" }));
  expect(screen.getByText("Synthetic skipped")).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Inspect" }));
  expect(screen.getByText("Blocked by · win")).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "All" }));
  fireEvent.change(screen.getByRole("searchbox", { name: "Search runs" }), {
    target: { value: "102" },
  });
  expect(screen.getByText("Synthetic unpriced")).toBeInTheDocument();
  expect(screen.queryByText("Synthetic winner")).not.toBeInTheDocument();
  expect(JSON.stringify(runs)).toBe(before);
});
it("paginates all runs and resets to the first page when searching", () => {
  render(
    <RunBrowser
      runs={Array.from({ length: 15 }, (_, i) => ({
        id: String(i),
        team: `Synthetic ${i}`,
        status: "pending",
        entry_at: "",
      }))}
    />,
  );
  expect(screen.queryByText("Synthetic 14")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  expect(screen.getByText("Synthetic 14")).toBeInTheDocument();
  fireEvent.change(screen.getByRole("searchbox"), {
    target: { value: "Synthetic 0" },
  });
  expect(screen.getByText("Synthetic 0")).toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Previous" })).toBeDisabled();
});
