import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { sharedRunMap, runMapColor } from "./runMapData";
import { RunEventTimeline } from "./RunEventTimeline";
import type { SharedSimulation } from "./sharedSimulation";
import type { Overview } from "./api";
afterEach(cleanup);
const identity = {
  competition: "virtual-football",
  season_id: "200",
  strategy_id: "champion-next-win",
  team: "Synthetic",
  entry_at: "2026-10-20T10:00:00Z",
};
const data = {
  status: "available",
  runs: [
    {
      ...identity,
      id: "selected",
      status: "win",
      net_minor: 10000,
      total_staked_minor: 10000,
      gross_payout_minor: 20000,
      released_at: "2026-10-20T10:10:00Z",
      estimate: {
        allowance_minor: 20000,
        plan: { total_minor: 10000, profit_per_attempt_minor: 10000 },
      },
    },
    {
      ...identity,
      season_id: "201",
      id: "skipped",
      status: "skipped_overlap",
      blocked_by: "selected",
      observed_window: { result: "win", ended_at: "2026-10-20T10:05:00Z" },
    },
    {
      ...identity,
      season_id: "202",
      id: "loss",
      status: "skipped_overlap",
      blocked_by: "selected",
      observed_window: { result: "no_win", ended_at: "2026-10-20T10:08:00Z" },
    },
  ],
} as SharedSimulation;
it("colours skipped outcomes separately and gives them no financial result", () => {
  const map = sharedRunMap(data)!;
  expect(runMapColor(map.runs[0])).toBe("#81c9a0");
  expect(runMapColor(map.runs[1])).toBe("#62aee8");
  expect(runMapColor(map.runs[2])).toBe("#d8ab62");
  expect(map.runs[1].net_minor).toBeNull();
  expect(map.runs[1].total_staked_minor).toBe(0);
});
it("shows overlapping entries, a colour legend and observed-only inspection", () => {
  const competitions = [
    { id: "virtual-football", name: "English" },
  ] as Overview["competitions"];
  render(
    <RunEventTimeline
      data={sharedRunMap(data)}
      competitions={competitions}
      shared
    />,
  );
  expect(
    screen.getAllByRole("button", {
      name: /English Next Win Synthetic season/,
    }),
  ).toHaveLength(3);
  expect(screen.getByLabelText("Run map colour legend")).toHaveTextContent(
    "Skipped · win observed",
  );
  fireEvent.click(screen.getByRole("button", { name: /season 201/ }));
  expect(screen.getByText(/not included in profit/)).toBeInTheDocument();
  expect(screen.getByText(/Active run: selected/)).toBeInTheDocument();
});
it("plots scheduled attempts instead of a five-day archive receipt span", () => {
  const fixtures = [
    { scheduled_start_at: "2026-10-20T10:00:00Z", won: false },
    { scheduled_start_at: "2026-10-20T10:04:00Z", won: true },
    { scheduled_start_at: "2026-10-20T10:08:00Z", won: true },
  ];
  const archive = "2026-10-25T12:00:00Z";
  const view = {
    ...data,
    runs: [
      { ...data.runs[0], fixtures, released_at: archive,
        attempts: [{ scheduled_start_at: fixtures[0].scheduled_start_at },
          { scheduled_start_at: fixtures[1].scheduled_start_at }] },
      { ...data.runs[1], fixtures, observed_window: { result: "win", ended_at: archive },
        overlap_basis: "unverified_late_archive_timing" },
    ],
  } as SharedSimulation;
  const map = sharedRunMap(view)!;
  expect(map.runs.map((r) => r.event_at)).toEqual([
    fixtures[1].scheduled_start_at, fixtures[1].scheduled_start_at,
  ]);
  expect(map.runs[0].result_observed_at).toBe(archive);
  expect(map.runs[1].overlap_basis).toBe("unverified_late_archive_timing");
  expect(map.runs[1].span_basis).toBe("scheduled_attempts_not_event_finish");
});
