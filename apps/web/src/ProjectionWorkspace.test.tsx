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

it("replays entered capital and shares returned amounts across all pages without scaling", async () => {
  const fetcher = vi.fn().mockImplementation(async (url: string) => {
    const query = new URL(url, "http://localhost").searchParams;
    const capital = Number(query.get("capital"));
    const net = capital === 14000 ? 12345 : 10000;
    const stake = capital === 14000 ? 30000 : 20000;
    return {
      ok: true,
      status: 200,
      json: async () => ({
        ...baseline,
        starting_capital_minor: capital * 100,
        balance_minor: capital * 100 + net,
        runs: [
          {
            ...baseline.runs[0],
            total_staked_minor: stake,
            gross_payout_minor: stake + net,
            net_minor: net,
            attempts: [
              {
                ...baseline.runs[0].attempts![0],
                stake_minor: stake,
                payout_minor: stake + net,
              },
            ],
          },
        ],
      }),
    };
  });
  vi.stubGlobal("fetch", fetcher);
  const ui = render(
    <ProjectionWorkspace data={data} league="all" page="Overview" />,
  );
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(screen.getAllByText("₦7,100.00").length).toBeGreaterThan(0);
  fireEvent.change(screen.getByLabelText("Initial capital"), {
    target: { value: "14000" },
  });
  expect(screen.queryByText("Current capital")).not.toBeInTheDocument();
  expect(screen.queryByText("₦14,200.00")).not.toBeInTheDocument();
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher.mock.calls.at(-1)?.[0]).toContain("capital=14000");
  expect(screen.getByText("Retrospective research")).toBeInTheDocument();
  expect(screen.getAllByText("₦14,123.45").length).toBeGreaterThan(0);
  const day = screen.getByText("2026-10-18").closest("tr")!;
  for (const money of ["₦123.45", "₦300.00", "₦423.45"])
    expect(day).toHaveTextContent(money);
  ui.rerender(<ProjectionWorkspace data={data} league="all" page="History" />);
  const row = screen.getByText("euro / 100").closest("tr")!;
  expect(
    within(row)
      .getAllByRole("cell")
      .slice(-4, -1)
      .map((c) => c.textContent),
  ).toEqual(["₦300.00", "₦423.45", "₦123.45"]);
  fireEvent.click(within(row).getByRole("button", { name: "Inspect" }));
  expect(screen.getByText("1.50")).toBeInTheDocument();
  ui.rerender(
    <ProjectionWorkspace data={data} league="all" page="Strategies" />,
  );
  expect(screen.getAllByText("₦123.45").length).toBeGreaterThan(0);
  expect(screen.getByText("₦100.00")).toBeInTheDocument(); // Minimum stake is never multiplied.
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher).toHaveBeenCalledTimes(2); // Page changes reuse the same scenario.
  expect(
    JSON.parse(localStorage.getItem("kiotrad:simulator:v1")!).capital,
  ).toBe(14000);
  expect(data.shared_simulation?.balance_minor).toBe(710000);
});

it("changes rules through a financial replay and hides amounts while the new selection is pending or invalid", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({ ok: true, status: 200, json: async () => baseline });
  vi.stubGlobal("fetch", fetcher);
  render(<ProjectionWorkspace data={data} league="all" page="Strategies" />);
  fireEvent.click(screen.getByText("Next Win", { selector: ".strategy-name" }));
  await act(() => vi.advanceTimersByTimeAsync(401));
  fireEvent.change(screen.getByLabelText("champion-next-win qualification"), {
    target: { value: "6" },
  });
  expect(screen.queryByText("Current capital")).not.toBeInTheDocument();
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher.mock.calls[1][0]).toContain("next_win_qualification=6");
  expect(fetcher.mock.calls[1][0]).toContain("staking_model=payout_carry_v1");
  fireEvent.change(screen.getByLabelText("champion-next-win attempts"), {
    target: { value: "6" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Fixed capital" }));
  fireEvent.click(screen.getByRole("button", { name: "×2" }));
  await act(() => vi.advanceTimersByTimeAsync(401));
  expect(fetcher.mock.calls.at(-1)?.[0]).toContain("capital_mode=fixed");
  expect(fetcher.mock.calls.at(-1)?.[0]).toContain("allowance=2");
  expect(screen.getByRole("button", { name: "Fixed capital" })).toHaveAttribute(
    "aria-pressed",
    "true",
  );
  fireEvent.change(screen.getByLabelText("Initial capital"), {
    target: { value: "0" },
  });
  expect(screen.queryByText("Current capital")).not.toBeInTheDocument();
});
it("keeps current recorded qualification on Overview separately from scenario settings", async () => {
  vi.stubGlobal(
    "fetch",
    vi
      .fn()
      .mockResolvedValue({
        ok: true,
        json: async () => ({
          ...baseline,
          status: "available",
          settings: {},
          source_as_of: "2026-10-20T12:00:00Z",
          data_revision: "test",
        }),
      }),
  );
  render(
    <ProjectionWorkspace
      data={{
        ...data,
        strategy_monitoring: [
          {
            competition: "virtual-euro",
            strategy_id: "champion-next-win",
            season_id: "200",
            team: "Current early winner",
            trigger_day: 2,
            status: "not_qualified",
          },
        ],
      }}
      league="all"
      page="Overview"
    />,
  );
  await act(() => vi.advanceTimersByTimeAsync(401));
  const section = screen.getByRole("region", { name: "Current qualification" });
  expect(within(section).getByText("Current early winner")).toBeInTheDocument();
  expect(within(section).getByText("not qualified")).toBeInTheDocument();
  expect(
    within(section).getByText(/Recorded production rules/),
  ).toBeInTheDocument();
  expect(
    within(section).queryByText("Stake & results"),
  ).not.toBeInTheDocument();
});
