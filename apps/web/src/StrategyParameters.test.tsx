import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { StrategyParameters } from "./StrategyParameters";
afterEach(cleanup);
it("edits each strategy independently and retains legacy defaults", () => {
  const change = vi.fn();
  const settings = {
    capital: 30000,
    qualification: 5,
    attempts: 4,
    allowance: 1,
    strategy: "all",
    next_season_qualification: 6,
    next_season_attempts: 5,
  };
  render(
    <>
      <StrategyParameters
        id="champion-next-win"
        settings={settings}
        change={change}
      />
      <StrategyParameters
        id="champion-next-season"
        settings={settings}
        change={change}
      />
    </>,
  );
  expect(screen.getByLabelText("champion-next-win attempts")).toHaveValue(4);
  expect(screen.getByLabelText("champion-next-season attempts")).toHaveValue(5);
  expect(
    screen.getByLabelText("champion-next-season qualification"),
  ).toHaveValue(6);
  fireEvent.change(screen.getByLabelText("champion-next-win attempts"), {
    target: { value: "6" },
  });
  expect(change).toHaveBeenCalledWith("next_win_attempts", 6);
  expect(screen.getByLabelText("champion-next-season attempts")).toHaveValue(5);
});
