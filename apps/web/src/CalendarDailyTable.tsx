import { Fragment, useState } from "react";
import { CalendarDayRuns } from "./CalendarDayRuns";
import "./calendar-daily.css";
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
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const toggle = (date: string) =>
    setExpanded((previous) => {
      const next = new Set(previous);
      if (next.has(date)) next.delete(date);
      else next.add(date);
      return next;
    });
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
      settled: [...settled].sort(
        (a, b) =>
          a.released_at!.localeCompare(b.released_at!) ||
          a.id.localeCompare(b.id),
      ),
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
        Lagos. Expand a date to inspect its runs in release order. Entire run
        stakes are included on that date, even when attempts span midnight.
        Pending and skipped runs are excluded.
      </p>
      {!available && (
        <p role="status">
          Daily financial totals unavailable until the simulation is ready.
        </p>
      )}
      <div className="calendar-table-scroll">
        <table
          className="calendar-daily-table"
          aria-label="Daily simulation totals"
        >
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
              <Fragment key={r.date}>
                <tr>
                  <td>
                    <button
                      type="button"
                      className="calendar-day-toggle"
                      aria-label={`Inspect runs for ${r.date}`}
                      aria-expanded={expanded.has(r.date)}
                      aria-controls={`daily-runs-${r.date}`}
                      disabled={!r.count}
                      onClick={() => toggle(r.date)}
                    >
                      <span aria-hidden="true">
                        {r.count ? (expanded.has(r.date) ? "−" : "+") : "·"}
                      </span>
                      <span>{r.date}</span>
                    </button>
                    <small>
                      {available ? ` · ${r.count} settled runs` : ""}
                    </small>
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
                {expanded.has(r.date) && r.count > 0 && (
                  <tr
                    className="calendar-expanded-row"
                    id={`daily-runs-${r.date}`}
                  >
                    <td colSpan={5}>
                      <CalendarDayRuns
                        date={r.date}
                        runs={r.settled}
                        modeled={!!simulation?.settlement_model}
                      />
                    </td>
                  </tr>
                )}
              </Fragment>
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
