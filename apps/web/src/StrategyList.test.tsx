import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { StrategyList } from "./StrategyList";
import type { Strategy } from "./api";
import type { SharedRun } from "./sharedSimulation";
afterEach(cleanup);
const strategy: Strategy = {
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
};
const settings = {
  capital: 30000,
  qualification: 5,
  attempts: 4,
  allowance: 1,
  strategy: "all",
};
const runs: SharedRun[] = [
  {
    id: "win",
    strategy_id: strategy.id,
    status: "win",
    entry_at: "2026-10-20T10:00:00Z",
    released_at: "2026-10-20T10:04:00Z",
    net_minor: 12000,
    estimate: {
      allowance_minor: 20000,
      plan: { total_minor: 20000, profit_per_attempt_minor: 10000 },
    },
  },
];
it("keeps strategy controls outside closed accordions and updates header net", () => {
  const change = vi.fn();
  const props = { strategies: [strategy], settings, change, sharedRuns: runs };
  const { container, rerender } = render(<StrategyList {...props} />);
  const input = screen.getByLabelText("champion-next-win attempts");
  expect(input.closest("details")).toBeNull();
  expect(container.querySelector(".strategy-row")).not.toHaveAttribute("open");
  expect(container.querySelector(".strategy-row > summary")).toHaveTextContent(
    "₦120.00",
  );
  fireEvent.change(input, { target: { value: "6" } });
  expect(change).toHaveBeenCalledWith("next_win_attempts", 6);
  rerender(
    <StrategyList {...props} sharedRuns={[{ ...runs[0], net_minor: 25000 }]} />,
  );
  expect(container.querySelector(".strategy-row > summary")).toHaveTextContent(
    "₦250.00",
  );
});
it("does not count skipped previews or pending results in header net", () => {
  const { container } = render(
    <StrategyList
      strategies={[strategy]}
      sharedRuns={[
        ...runs,
        { ...runs[0], id: "skip", status: "skipped_overlap", net_minor: 99999 },
        { ...runs[0], id: "pending", released_at: null, net_minor: 99999 },
      ]}
    />,
  );
  expect(container.querySelector(".strategy-row > summary")).toHaveTextContent(
    "₦120.00",
  );
});
