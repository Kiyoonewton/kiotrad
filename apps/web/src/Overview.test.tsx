import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { Overview } from "./Overview";
import type { Overview as Data } from "./api";

afterEach(cleanup);
const data: Data = {
  schema_version: "1",
  mode: "foundation",
  currency: "NGN",
  display_timezone: "Africa/Lagos",
  competitions: [
    { id: "virtual-football", name: "English", feed_status: "fresh" },
    { id: "virtual-euro", name: "Euro", feed_status: "fresh" },
  ],
  strategies: [
    {
      id: "champion-next-win",
      name: "Champion — Next Win",
      version: "0.2.0-observation",
      status: "observation",
      monitoring_enabled: true,
      bankroll_enabled: false,
      description: "Observe a next win.",
      entry: "After a trigger",
      rules: {
        max_attempts: 4,
        minimum_stake_minor: 10000,
        profit_per_attempt_minor: 10000,
      },
      unresolved: [],
    },
  ],
  strategy_monitoring: [
    {
      competition: "virtual-football",
      strategy_id: "champion-next-win",
      status: "waiting_for_history",
    },
    {
      competition: "virtual-euro",
      strategy_id: "champion-next-win",
      status: "watching_fixture",
      team: "Example champion",
      trigger_day: 4,
      attempt: 1,
      next_match: {
        matchday: 5,
        home_team: "Example champion",
        away_team: "Opponent",
      },
    },
  ],
};

it("connects Overview activity and expanded strategy rows to monitoring", () => {
  render(<Overview data={data} league="all" />);
  const activity = within(
    screen.getByRole("region", { name: "Strategy observations." }),
  );
  expect(activity.getByText("waiting for history")).toBeInTheDocument();
  expect(activity.getByText(/snapshot count alone/)).toBeInTheDocument();
  expect(activity.getByText(/observation 1\/4/)).toBeInTheDocument();
  expect(screen.getAllByText("watching fixture")).toHaveLength(2);
  expect(screen.getByText("Bankroll not configured")).toBeInTheDocument();
  expect(
    screen.queryByText("Waiting for the first signal."),
  ).not.toBeInTheDocument();
});

it("filters both monitoring locations by league and removes previous league observations", () => {
  const view = render(<Overview data={data} league="virtual-euro" />);
  expect(screen.queryByText("waiting for history")).not.toBeInTheDocument();
  view.rerender(<Overview data={data} league="virtual-football" />);
  expect(screen.queryByText("watching fixture")).not.toBeInTheDocument();
  expect(screen.queryByText(/Day 5/)).not.toBeInTheDocument();
});

it("distinguishes unavailable monitoring and absent API observations", () => {
  const view = render(
    <Overview
      data={{
        ...data,
        strategy_monitoring: [
          { competition: "virtual-football", status: "monitoring_unavailable" },
        ],
      }}
      league="all"
    />,
  );
  expect(screen.getAllByText(/could not read its data/)).toHaveLength(2);
  view.rerender(
    <Overview
      data={{ ...data, strategy_monitoring: undefined }}
      league="all"
    />,
  );
  expect(
    screen.getAllByText(/No monitoring observations returned/),
  ).toHaveLength(2);
  expect(screen.queryByText("waiting for history")).not.toBeInTheDocument();
});

it("shows the reported missing evidence instead of a generic wait", () => {
  render(
    <Overview
      data={{
        ...data,
        strategy_monitoring: [
          {
            competition: "virtual-football",
            strategy_id: "champion-next-win",
            status: "waiting_for_history",
            reason: "missing_linked_previous_season",
          },
        ],
      }}
      league="all"
    />,
  );
  expect(
    screen.getAllByText(/No unique recorded link to the previous season/),
  ).toHaveLength(2);
});
