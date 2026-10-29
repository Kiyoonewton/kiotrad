import { naira, type Overview } from "./api";
import { lagosDay } from "./researchChartData";
import type { SharedSimulation } from "./sharedSimulation";

type Coverage = NonNullable<Overview["calendar_coverage"]>["leagues"];
export function CalendarDailyTable({
  coverage,
  dates,
  simulation,
  league,
}: {
  coverage: Coverage;
  dates: string[];
  simulation?: SharedSimulation | null;
  league: string;
}) {
  const available = simulation?.status === "available";
  const runs = available
    ? simulation.runs.filter(
        (r) =>
          r.estimate &&
          r.funded !== false &&
          !r.status.startsWith("skipped_") &&
          r.net_minor != null &&
          r.released_at &&
          (league === "all" || r.competition === league),
      )
    : [];
  const days = [
    ...new Set([...dates, ...runs.map((r) => lagosDay(r.released_at!))]),
  ].sort();
  const time = (v: string) =>
    new Date(v).toLocaleTimeString("en-GB", {
      timeZone: "Africa/Lagos",
      hour12: false,
    });
  const rows = days.map((date) => {
    const ranges = coverage
      .flatMap((c) => c.days ?? [])
      .filter((d) => d.date === date);
    const first = ranges.map((r) => r.first_at).sort()[0];
    const last = ranges
      .map((r) => r.last_at)
      .sort()
      .at(-1);
    const settled = runs.filter((r) => lagosDay(r.released_at!) === date);
    return {
      date,
      first,
      last,
      count: settled.length,
      profit: settled.reduce((n, r) => n + r.net_minor!, 0),
      stake: settled.reduce((n, r) => n + (r.total_staked_minor ?? 0), 0),
      payout: settled.reduce((n, r) => n + (r.gross_payout_minor ?? 0), 0),
    };
  });
  const total = rows.reduce(
    (s, r) => ({
      profit: s.profit + r.profit,
      stake: s.stake + r.stake,
      payout: s.payout + r.payout,
    }),
    { profit: 0, stake: 0, payout: 0 },
  );
  return (
    <>
      <p className="subtle">
        Accepted settled runs only · totals booked on the run’s release date in
        Lagos. Entire run stakes are included on that date, even when attempts
        span midnight. Pending and skipped runs are excluded.
      </p>
      {!available && (
        <p role="status">
          Daily financial totals unavailable until the simulation is ready.
        </p>
      )}
      <div style={{ overflowX: "auto" }}>
        <table aria-label="Daily simulation totals">
          <thead>
            <tr>
              <th>Date</th>
              <th>Recorded kickoff times · WAT</th>
              <th>Profit / loss</th>
              <th>Stake sum</th>
              <th>Payout</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.date}>
                <td>
                  {r.date}
                  <small>{available ? ` · ${r.count} settled runs` : ""}</small>
                </td>
                <td>
                  {r.first && r.last
                    ? `${time(r.first)} – ${time(r.last)}`
                    : "—"}
                </td>
                <td>{available ? naira(r.profit) : "—"}</td>
                <td>{available ? naira(r.stake) : "—"}</td>
                <td>{available ? naira(r.payout) : "—"}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <th>Total</th>
              <td>All listed dates</td>
              <td>{available ? naira(total.profit) : "—"}</td>
              <td>{available ? naira(total.stake) : "—"}</td>
              <td>{available ? naira(total.payout) : "—"}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      {!days.length && <p>No timed records available.</p>}
      <small>
        Profit / loss is simulated net after all run stakes. Times are scheduled
        match times, not collection or settlement times.
      </small>
    </>
  );
}
