import { expect, it } from "vitest";
import { projectMoney } from "./capitalProjection";

it("scales every nested monetary value without modifying odds, evidence, counters or source", () => {
  const source = {
    starting_capital_minor: 700000,
    balance_minor: 710123,
    runs: [
      {
        id: "raw-run",
        status: "win",
        net_minor: 10123,
        total_staked_minor: 10000,
        gross_payout_minor: 20123,
        attempts: [
          {
            stake_minor: 10000,
            actual_odds_scaled: 20123,
            payout_minor: null,
            matchday: 5,
          },
        ],
        estimate: { plan: { profit_per_attempt_minor: 10123 } },
      },
    ],
    equity: [{ capital_minor: 710123 }],
    settlement_model: { seconds: 201 },
    dates: ["2026-10-18"],
    timing_sensitivity: [{ balance_minor: 700000, completed_runs: 1 }],
  };
  const before = JSON.stringify(source);
  for (const factor of [1, 2, 0.1, 12345 / 7000]) {
    const projection = projectMoney(source, factor);
    expect(projection.balance_minor).toBe(source.balance_minor * factor);
    expect(projection.runs[0].estimate.plan.profit_per_attempt_minor).toBe(
      10123 * factor,
    );
    expect(projection.equity[0].capital_minor).toBe(710123 * factor);
    expect(projection.runs[0].attempts[0].actual_odds_scaled).toBe(20123);
    expect(projection.runs[0].attempts[0].payout_minor).toBeNull();
    expect(projection.runs[0].status).toBe("win");
    expect(projection.settlement_model.seconds).toBe(201);
    expect(projection.dates).toEqual(source.dates);
  }
  expect(JSON.stringify(source)).toBe(before);
});

it("rejects invalid factors rather than fabricating a zero or negative balance", () => {
  for (const factor of [NaN, Infinity, 0, -1])
    expect(() => projectMoney({}, factor)).toThrow();
});
