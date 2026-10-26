import type { Overview } from "./api";
export type ResearchRun = NonNullable<
  Overview["research_simulation"]
>["runs"][number];
export const lagosDay = (value: string) =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Lagos",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(value));
export const eventLabel = (value: string) =>
  new Intl.DateTimeFormat("en-NG", {
    timeZone: "Africa/Lagos",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(new Date(value));
export function chartData(
  runs: ResearchRun[],
  initial: number,
  bucketHours = 24,
) {
  const completed = runs
    .filter((r) => r.net_minor !== null && r.event_at)
    .sort(
      (a, b) =>
        a.event_at!.localeCompare(b.event_at!) ||
        `${a.competition}:${a.season_id}:${a.strategy_id}`.localeCompare(
          `${b.competition}:${b.season_id}:${b.strategy_id}`,
        ),
    );
  const grouped = new Map<string, { run: ResearchRun; count: number }>();
  for (const run of completed) {
    const existing = grouped.get(run.event_at!);
    if (existing) {
      existing.count++;
      existing.run = {
        ...existing.run,
        team: `${existing.count} simultaneous runs`,
        net_minor: existing.run.net_minor! + run.net_minor!,
        total_staked_minor:
          existing.run.total_staked_minor + run.total_staked_minor,
        gross_payout_minor:
          existing.run.gross_payout_minor + run.gross_payout_minor,
      };
    } else grouped.set(run.event_at!, { run: { ...run }, count: 1 });
  }
  let value = initial,
    peak = initial,
    drawdown = 0;
  const points = [...grouped.values()].map(({ run, count }) => {
    value += run.net_minor!;
    peak = Math.max(peak, value);
    drawdown = Math.max(drawdown, peak - value);
    return { run, value, count };
  });
  const candles: {
    day: string;
    open: number;
    close: number;
    high: number;
    low: number;
    count: number;
  }[] = [];
  let previous = initial;
  let previousBucket: number | null = null;
  for (const point of points) {
    const time = Date.parse(point.run.event_at!);
    const shifted = time + 3600000;
    const bucket =
      Math.floor(shifted / (bucketHours * 3600000)) * bucketHours * 3600000 -
      3600000;
    const day =
      bucketHours >= 24
        ? lagosDay(new Date(bucket).toISOString())
        : eventLabel(new Date(bucket).toISOString());
    let candle = candles.at(-1);
    if (!candle || previousBucket !== bucket) {
      candle = {
        day,
        open: previous,
        close: previous,
        high: previous,
        low: previous,
        count: 0,
      };
      candles.push(candle);
    }
    previousBucket = bucket;
    candle.close = point.value;
    candle.high = Math.max(candle.high, point.value);
    candle.low = Math.min(candle.low, point.value);
    candle.count += point.count;
    previous = point.value;
  }
  return { points, candles, drawdown };
}
