import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { Overview } from "./Overview";
import { PaperHistory } from "./PaperActivity";
import type { Overview as Data } from "./api";

afterEach(cleanup);
const data: Data = {
  schema_version: "1",
  mode: "foundation",
  currency: "NGN",
  display_timezone: "Africa/Lagos",
  competitions: [],
  strategies: [],
  bankroll: {
    status: "paper",
    balance_minor: 547100,
    available_minor: 527100,
    committed_minor: 20000,
    net_profit_minor: 0,
    target_balance_minor: 1094200,
  },
  capital_history: [{ at: "2026-10-24T10:00:00Z", balance_minor: 547100 }],
  active_run: {
    id: "run",
    team: "Example champion",
    competition: "virtual-football",
    season_id: "200",
    strategy_id: "champion-next-win",
    status: "awaiting_result",
    net_minor: 0,
    attempts: [
      {
        attempt: 1,
        matchday: 5,
        stake_minor: 20000,
        odds: "1.5",
        status: "pending",
        potential_payout_minor: 30000,
        scheduled_start_at: "2026-10-24T10:01:00Z",
      },
    ],
  },
};

it("distinguishes reserved cash, potential payout and settled capital", () => {
  render(<Overview data={data} league="all" />);
  expect(screen.getByText("₦5,471.00")).toBeInTheDocument();
  expect(
    screen.getByText(/Available ₦5,271.00 · Reserved ₦200.00/),
  ).toBeInTheDocument();
  expect(screen.getByText(/Potential gross payout ₦300.00/)).toHaveTextContent(
    "result unconfirmed",
  );
  expect(screen.getByText(/Scheduled .*11:01:00 WAT/)).toBeInTheDocument();
  expect(
    screen.queryByRole("img", { name: /Paper capital after/ }),
  ).not.toBeInTheDocument();
  expect(
    screen.getByText("Waiting for the first paper settlement."),
  ).toBeInTheDocument();
});

it("plots recorded settlements and exposes skipped overlaps separately from profits", () => {
  const settled = {
    ...data,
    active_run: null,
    bankroll: {
      ...data.bankroll!,
      balance_minor: 557100,
      available_minor: 557100,
      committed_minor: 0,
      net_profit_minor: 10000,
    },
    capital_history: [
      ...data.capital_history!,
      { at: "2026-10-24T10:04:00Z", balance_minor: 557100 },
    ],
    recent_runs: [
      {
        ...data.active_run!,
        status: "overlap_skipped",
        attempts: [],
        net_minor: 0,
      },
    ],
  };
  render(
    <>
      <Overview data={settled} league="all" />
      <PaperHistory data={settled} />
    </>,
  );
  expect(
    screen.getByRole("img", { name: "Paper capital after 1 settled attempts" }),
  ).toBeInTheDocument();
  expect(screen.getByText("overlap skipped")).toBeInTheDocument();
  expect(screen.getAllByText("₦0.00")).toHaveLength(2);
});

it("keeps unavailable balances unknown instead of showing zero", () => {
  render(
    <Overview
      data={{
        ...data,
        bankroll: { status: "unavailable", balance_minor: null },
        active_run: null,
        capital_history: [],
      }}
      league="all"
    />,
  );
  expect(screen.getByText("Bankroll unavailable")).toBeInTheDocument();
  expect(screen.getByText("₦ —")).toBeInTheDocument();
});
