import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { SimulationHistory } from "./SimulationHistory";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const page = (season: string, current = 1) => ({
  schema_version: "1",
  rows: [
    {
      competition: "virtual-football",
      season_id: season,
      strategy_id: "champion-next-win",
      team: "Synthetic champion",
      status: "observed_win",
      simulation: {
        mode: "event_order_simulation",
        status: "win",
        starting_capital_minor: 700000,
        total_staked_minor: 20000,
        gross_payout_minor: 30000,
        realized_net_minor: 10000,
        net_minor: 10000,
        attempts: [],
      },
    },
  ],
  pagination: { page: current, per_page: 10, total: 20, last_page: 2 },
});
const response = (body: unknown) => ({ ok: true, json: async () => body });

it("pages stored simulation results without showing legacy entry-closed decisions", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(response(page("200")))
    .mockResolvedValueOnce(response(page("100", 2)));
  vi.stubGlobal("fetch", fetcher);
  render(<SimulationHistory league="all" />);
  expect(await screen.findByText("football / 200")).toBeInTheDocument();
  expect(screen.getAllByText("₦200.00")[0]).toBeInTheDocument();
  expect(screen.getAllByText("₦300.00")[0]).toBeInTheDocument();
  expect(screen.getAllByText("₦100.00")[0]).toBeInTheDocument();
  expect(screen.queryByText("entry closed")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  expect(await screen.findByText("football / 100")).toBeInTheDocument();
  expect(fetcher.mock.calls[1][0]).toContain("page=2");
  expect(screen.getByRole("button", { name: "Next" })).toBeDisabled();
});

it("keeps the selected historical date when a slower previous response returns", async () => {
  let resolveFirst!: (value: unknown) => void;
  const fetcher = vi
    .fn()
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveFirst = resolve;
        }),
    )
    .mockResolvedValueOnce(response(page("dated-season")));
  vi.stubGlobal("fetch", fetcher);
  render(<SimulationHistory league="virtual-football" />);
  fireEvent.change(screen.getByLabelText(/Season event date/), {
    target: { value: "2026-10-20" },
  });
  expect(
    await screen.findByText("football / dated-season"),
  ).toBeInTheDocument();
  resolveFirst(response(page("stale-season")));
  expect(fetcher.mock.calls[1][0]).toContain("from=2026-10-20");
  expect(screen.queryByText("football / stale-season")).not.toBeInTheDocument();
});

it("uses the shared run in history instead of an older fixed-target replay", async () => {
  const body = {
    ...page("200"),
    calculation_mode: "shared_capital_simulation",
    rows: [
      {
        ...page("200").rows[0],
        shared_calculation_status: "available",
        shared_run: {
          id: "virtual-football:200:champion-next-win",
          entry_at: "2026-10-20T10:00:00Z",
          status: "win",
          capital_at_entry_minor: 5000000,
          total_staked_minor: 274600,
          gross_payout_minor: 370710,
          net_minor: 96110,
          estimate: {
            allowance_minor: 4997200,
            plan: { total_minor: 2498600, profit_per_attempt_minor: 96100 },
          },
          attempts: [],
        },
      },
    ],
  };
  vi.stubGlobal("fetch", vi.fn().mockResolvedValue(response(body)));
  render(<SimulationHistory league="all" />);
  expect((await screen.findAllByText("₦961.10")).length).toBeGreaterThan(0);
  expect(screen.getAllByText("₦2,746.00").length).toBeGreaterThan(0);
  expect(screen.queryByText("₦100.00")).not.toBeInTheDocument();
});
