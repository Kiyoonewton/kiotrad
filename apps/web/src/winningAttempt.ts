import type { SharedRun } from "./sharedSimulation";

export function winningAttempt(run: SharedRun): number | null {
  if (run.status !== "win") return null;
  return (
    run.attempts?.find((attempt) => (attempt.payout_minor ?? 0) > 0)?.attempt ??
    null
  );
}

export function dailyWinningAttempts(runs: SharedRun[]): string {
  const counts = new Map<number, number>();
  let unknown = 0;
  for (const run of runs) {
    if (run.status !== "win") continue;
    const attempt = winningAttempt(run);
    if (attempt === null) unknown++;
    else counts.set(attempt, (counts.get(attempt) ?? 0) + 1);
  }
  return [...counts]
    .sort(([a], [b]) => a - b)
    .map(
      ([attempt, count]) =>
        `${attempt} ${attempt === 1 ? "bet" : "bets"} × ${count}`,
    )
    .concat(unknown ? [`unknown × ${unknown}`] : [])
    .join(" · ");
}
