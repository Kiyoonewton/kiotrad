import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { TimingSensitivityPanel } from "./TimingSensitivityPanel";
import type { SharedSimulation } from "./sharedSimulation";
afterEach(cleanup);
it("labels modeled timing and exposes scenario balances and selection differences", () => {
  const data = {
    settlement_model: { seconds: 201, version: "test", basis: "simulation_assumption" },
    timing_sensitive: true,
    timing_sensitivity: [
      { settlement_seconds: 201, balance_minor: 5100000, completed_runs: 4,
        overlap_exclusions: 2, allocation_changed_vs_primary: false,
        accepted_only_in_scenario: [], accepted_only_in_primary: [] },
      { settlement_seconds: 180, balance_minor: 5200000, completed_runs: 5,
        overlap_exclusions: 1, allocation_changed_vs_primary: true,
        accepted_only_in_scenario: ["extra-run"], accepted_only_in_primary: [] },
      { settlement_seconds: 220, balance_minor: 4900000, completed_runs: 4,
        overlap_exclusions: 2, allocation_changed_vs_primary: false,
        accepted_only_in_scenario: [], accepted_only_in_primary: [] },
    ],
  } as unknown as SharedSimulation;
  render(<TimingSensitivityPanel data={data} />);
  expect(screen.getByText(/finish times are assumed/)).toBeInTheDocument();
  expect(screen.getByText("₦51,000.00")).toBeInTheDocument();
  expect(screen.getByText("₦52,000.00")).toBeInTheDocument();
  expect(screen.getByText("₦49,000.00")).toBeInTheDocument();
  expect(screen.getByText(/Only in this scenario: extra-run/)).toBeInTheDocument();
});
it("does not label a legacy capture-based replay as modeled", () => {
  const { container } = render(<TimingSensitivityPanel data={{} as SharedSimulation} />);
  expect(container).toBeEmptyDOMElement();
});
