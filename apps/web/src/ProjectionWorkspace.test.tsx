import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { ProjectionWorkspace } from "./ProjectionWorkspace";
import type { Overview } from "./api";
import type { ScenarioView } from "./useScenario";

const baseline: ScenarioView = {
  status: "available",
  starting_capital_minor: 700000,
  balance_minor: 710000,
  reserved_allowance_minor: 0,
  active_staked_minor: 0,
  active_run_id: null,
  seasons_assessed: 1,
  timing_basis: "scheduled",
  source_as_of: "2026-11-01T10:00:00Z",
  data_revision: "test",
  scenario_cache: "hit",
  settings: {
    capital: 7000,
    qualification: 5,
    attempts: 4,
    allowance: 1,
    strategy: "all",
  },
  runs: [
    {
      id: "virtual-euro:100:champion-next-win",
      competition: "virtual-euro",
      season_id: "100",
      strategy_id: "champion-next-win",
      team: "Example champion",
      entry_at: "2026-10-18T12:00:00Z",
      released_at: "2026-10-18T12:03:21Z",
      status: "win",
      total_staked_minor: 20000,
      gross_payout_minor: 30000,
      net_minor: 10000,
      estimate: {
        allowance_minor: 600000,
        plan: { total_minor: 600000, profit_per_attempt_minor: 10000 },
      },
      attempts: [
        {
          attempt: 1,
          matchday: 6,
          stake_minor: 20000,
          payout_minor: 30000,
          target_profit_minor: 10000,
          actual_odds_scaled: 15000,
        },
      ],
    },
  ],
};
const data: Overview = {
  schema_version: "1",
  mode: "foundation",
  currency: "NGN",
  display_timezone: "Africa/Lagos",
  competitions: [{ id: "virtual-euro", name: "Euro", feed_status: "fresh" }],
  strategies: [
    {
      id: "champion-next-win",
      name: "Next Win",
      version: "test",
      status: "observation",
      monitoring_enabled: true,
      bankroll_enabled: false,
      description: "Test",
      entry: "After trigger",
      unresolved: [],
      rules: {
        max_attempts: 4,
        minimum_stake_minor: 10000,
        profit_per_attempt_minor: 10000,
      },
    },
  ],
  shared_simulation: baseline,
  calendar_coverage: {
    status: "available",
    leagues: [
      {
        competition: "virtual-euro",
        dates: ["2026-10-18"],
        first_at: "2026-10-18T12:00:00Z",
        last_at: "2026-10-18T12:00:00Z",
        settled_fixtures: 8,
        unknown_timing_fixtures: 0,
      },
    ],
  },
};
beforeEach(() => {
  vi.useFakeTimers();
  localStorage.clear();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it("shares one exact capital factor across Overview, daily totals, History and Strategies without another capital replay", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({ ok: true, status: 200, json: async () => baseline });
  vi.stubGlobal("fetch", fetcher);
  const ui = render(
    <ProjectionWorkspace data={data} league="all" page="Overview" />,
  );
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(screen.getAllByText("₦7,100.00").length).toBeGreaterThan(0);
  for (const label of [
    "Capital mode",
    "Stop at net profit",
    "Qualification day",
    "Maximum attempts",
    "Allowance multiplier",
    "Simulation strategy",
  ])
    expect(screen.getByLabelText(label)).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("Initial capital"), {
    target: { value: "14000" },
  });
  expect(screen.getByRole("note")).toHaveTextContent("multiplier ×2");
  expect(screen.getAllByText("₦14,200.00").length).toBeGreaterThan(0);
  const day = screen.getByText("2026-10-18").closest("tr")!;
  expect(day).toHaveTextContent("₦200.00");
  expect(day).toHaveTextContent("₦400.00");
  expect(day).toHaveTextContent("₦600.00");
  ui.rerender(<ProjectionWorkspace data={data} league="all" page="History" />);
  expect(screen.getByLabelText("Initial capital")).toHaveValue(14000);
  const row = screen.getByText("euro / 100").closest("tr")!;
  expect(
    within(row)
      .getAllByRole("cell")
      .slice(-3)
      .map((c) => c.textContent),
  ).toEqual(["₦400.00", "₦600.00", "₦200.00"]);
  expect(row).toHaveTextContent("1.50");
  ui.rerender(
    <ProjectionWorkspace data={data} league="all" page="Strategies" />,
  );
  expect(screen.getByText("₦200.00")).toBeInTheDocument();
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher).toHaveBeenCalledTimes(1);
  expect(fetcher.mock.calls[0][0]).toContain("capital=7000");
  expect(
    JSON.parse(localStorage.getItem("kiotrad:simulator:v1")!).capital,
  ).toBe(14000);
  fireEvent.change(screen.getByLabelText("Initial capital"), {
    target: { value: "700" },
  });
  ui.rerender(<ProjectionWorkspace data={data} league="all" page="History" />);
  expect(screen.getByRole("note")).toHaveTextContent("multiplier ×0.1");
  expect(screen.getByText("euro / 100").closest("tr")).toHaveTextContent(
    "₦20.00",
  );
  expect(data.shared_simulation?.balance_minor).toBe(710000);
});

it("changes rules through a base replay and hides amounts while the new selection is pending or invalid", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({ ok: true, status: 200, json: async () => baseline });
  vi.stubGlobal("fetch", fetcher);
  render(<ProjectionWorkspace data={data} league="all" page="Overview" />);
  await act(() => vi.advanceTimersByTimeAsync(401));
  fireEvent.change(screen.getByLabelText("Qualification day"), {
    target: { value: "6" },
  });
  expect(screen.queryByText("Current capital")).not.toBeInTheDocument();
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher.mock.calls[1][0]).toContain("qualification=6");
  fireEvent.change(screen.getByLabelText("Initial capital"), {
    target: { value: "0" },
  });
  expect(screen.queryByText("Current capital")).not.toBeInTheDocument();
});
