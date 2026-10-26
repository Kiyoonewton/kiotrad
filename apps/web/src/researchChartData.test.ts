import { expect, it } from "vitest";
import { chartData, lagosDay, type ResearchRun } from "./researchChartData";
const run = (net: number, at: string): ResearchRun => ({
  competition: "virtual-football",
  season_id: at,
  strategy_id: "champion-next-win",
  team: "Synthetic",
  status: net > 0 ? "win" : "four_losses",
  net_minor: net,
  total_staked_minor: 10000,
  gross_payout_minor: 10000 + net,
  event_at: at,
});
it("builds daily OHLC from capital plus ordered run net, with losses included", () => {
  const result = chartData(
    [
      run(20000, "2026-10-20T10:00:00Z"),
      run(-5000, "2026-10-20T11:00:00Z"),
      run(-30000, "2026-10-21T10:00:00Z"),
    ],
    1400000,
  );
  expect(result.candles[0]).toEqual({
    day: "2026-10-20",
    open: 1400000,
    high: 1420000,
    low: 1400000,
    close: 1415000,
    count: 2,
  });
  expect(result.candles[1].close).toBe(1385000);
  expect(result.drawdown).toBe(35000);
});
it("buckets days in Lagos rather than the retrieval timezone", () => {
  expect(lagosDay("2026-10-20T23:30:00Z")).toBe("2026-10-21");
});
it("combines simultaneous settlements so candle extremes do not invent an event order", () => {
  const data = chartData(
    [run(10000, "2026-10-20T10:00:00Z"), run(-10000, "2026-10-20T10:00:00Z")],
    1400000,
  );
  expect(data.points).toHaveLength(1);
  expect(data.candles[0].high).toBe(1400000);
  expect(data.candles[0].count).toBe(2);
  expect(data.drawdown).toBe(0);
});
