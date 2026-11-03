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
it("shows qualified runs newest first and counts attempts to win", () => {
  render(
    <RunBrowser
      qualifiedOnly
      runs={[
        {
          id: "old",
          team: "Older winner",
          status: "win",
          entry_at: "2026-10-20T10:00:00Z",
          attempts: [
            {
              attempt: 1,
              matchday: 5,
              stake_minor: 10000,
              target_profit_minor: 10000,
              payout_minor: 0,
            },
            {
              attempt: 2,
              matchday: 6,
              stake_minor: 20000,
              target_profit_minor: 10000,
              payout_minor: 40000,
            },
          ],
        },
        {
          id: "no",
          team: "Unqualified team",
          status: "not_qualified",
          entry_at: "2026-10-22T10:00:00Z",
        },
        {
          id: "new",
          team: "Latest pending",
          status: "pending",
          entry_at: "2026-10-21T10:00:00Z",
        },
      ]}
    />,
  );
  expect(screen.queryByText("Unqualified team")).not.toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: /^All$/ }),
  ).not.toBeInTheDocument();
  const rows = screen.getAllByRole("row");
  expect(rows[1]).toHaveTextContent("Latest pending");
  expect(rows[2]).toHaveTextContent("Older winner");
  expect(rows[2].children[2]).toHaveTextContent("2");
  expect(rows[1].children[2]).toHaveTextContent("—");
});
it("shows skipped hypothetical amounts without losing the skipped label", () => {
  render(
    <RunBrowser
      qualifiedOnly
      runs={[
        {
          id: "skipped",
          team: "Skipped preview",
          entry_at: "2026-10-20T10:00:00Z",
          status: "skipped_overlap",
          hypothetical: {
            id: "skipped",
            entry_at: "2026-10-20T10:00:00Z",
            status: "win",
            total_staked_minor: 20000,
            gross_payout_minor: 32000,
            net_minor: 12000,
            attempts: [
              {
                attempt: 1,
                matchday: 1,
                stake_minor: 20000,
                target_profit_minor: 10000,
                payout_minor: 32000,
              },
            ],
          },
        },
      ]}
    />,
  );
  expect(screen.getByText("skipped overlap")).toBeInTheDocument();
  expect(screen.getByText(/Hypothetical · win/)).toBeInTheDocument();
  expect(screen.getByText("₦200.00")).toBeInTheDocument();
  expect(screen.getByText("₦320.00")).toBeInTheDocument();
  expect(screen.getByText("₦120.00")).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Inspect" }));
  expect(
    screen.getByText(/excluded from bankroll and totals/),
  ).toBeInTheDocument();
});
