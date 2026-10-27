import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { Simulator } from "./Simulator";
import type { Overview } from "./api";
vi.mock("./useScenario", async (original) => ({
  ...(await original<object>()),
  useScenario: () => ({ view: null, message: "Calculating scenario…" }),
}));
afterEach(() => {
  cleanup();
  localStorage.clear();
});
it("offers capital, qualification, attempts, allowance and strategy without a submit or deployment step", () => {
  const data: Overview = {
    schema_version: "1",
    mode: "foundation",
    currency: "NGN",
    display_timezone: "Africa/Lagos",
    competitions: [],
    strategies: [
      { id: "champion-next-win", name: "Champion — Next Win" },
      { id: "champion-next-season", name: "Champion — Next Season" },
    ].map((strategy) => ({
      ...strategy,
      version: "test",
      status: "simulation",
      monitoring_enabled: true,
      bankroll_enabled: false,
      description: "Synthetic strategy",
      entry: "Synthetic entry",
      unresolved: [],
      rules: {
        max_attempts: 4,
        minimum_stake_minor: 10000,
        profit_per_attempt_minor: 10000,
      },
    })),
    shared_simulation: {
      status: "available",
      starting_capital_minor: 5000000,
      balance_minor: 5000000,
      reserved_allowance_minor: 0,
      active_staked_minor: 0,
      active_run_id: null,
      seasons_assessed: 0,
      runs: [],
      timing_basis: "modeled",
    },
  };
  render(<Simulator data={data} league="all" />);
  expect(screen.getByLabelText("Initial capital")).toHaveValue(50000);
  fireEvent.change(screen.getByLabelText("Qualification day"), {
    target: { value: "6" },
  });
  fireEvent.change(screen.getByLabelText("Maximum attempts"), {
    target: { value: "5" },
  });
  fireEvent.change(screen.getByLabelText("Allowance multiplier"), {
    target: { value: "2" },
  });
  fireEvent.change(screen.getByLabelText("Simulation strategy"), {
    target: { value: "champion-next-win" },
  });
  expect(screen.getByLabelText("Qualification day")).toHaveValue(6);
  expect(screen.getByLabelText("Maximum attempts")).toHaveValue(5);
  expect(screen.getByLabelText("Allowance multiplier")).toHaveValue("2");
  expect(screen.getByLabelText("Simulation strategy")).toHaveValue(
    "champion-next-win",
  );
  expect(
    screen.queryByRole("button", { name: /apply|save|submit/i }),
  ).not.toBeInTheDocument();
});
