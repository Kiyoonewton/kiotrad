import type { Overview } from "./api";
import { eventLabel } from "./researchChartData";
export function RunEventTimeline({
  data,
  competitions,
}: {
  data: Overview["research_simulation"];
  competitions: Overview["competitions"];
}) {
  const events = (data?.runs ?? []).filter(
    (r) =>
      r.event_at &&
      r.net_minor !== null &&
      competitions.some((c) => c.id === r.competition),
  );
  const times = events.map((r) => Date.parse(r.event_at!));
  const min = Math.min(...times),
    max = Math.max(...times);
  return (
    <section className="quiet-panel run-event-panel">
      <p className="eyebrow">Across the leagues</p>
      <h2>Simulation event map</h2>
      <p className="subtle">Scheduled time of each run’s final attempt · WAT</p>
      {events.length ? (
        <>
          <svg
            viewBox={`0 0 600 ${competitions.length * 65 + 35}`}
            role="img"
            aria-label="Completed simulation events by league and scheduled time"
          >
            {competitions.map((c, i) => (
              <g key={c.id}>
                <text x="0" y={i * 65 + 30}>
                  {c.name}
                </text>
                <line
                  x1="105"
                  x2="580"
                  y1={i * 65 + 26}
                  y2={i * 65 + 26}
                  stroke="#34443d"
                />
                {events
                  .filter((r) => r.competition === c.id)
                  .map((r) => (
                    <circle
                      key={`${r.season_id}:${r.strategy_id}`}
                      cx={
                        110 +
                        ((Date.parse(r.event_at!) - min) /
                          Math.max(1, max - min)) *
                          460
                      }
                      cy={
                        i * 65 +
                        26 +
                        (r.strategy_id === "champion-next-win" ? 7 : -7)
                      }
                      r="4"
                      fill={r.net_minor! >= 0 ? "#81c9a0" : "#e88f86"}
                    >
                      <title>
                        {c.name} · {r.team} · {r.season_id} ·{" "}
                        {eventLabel(r.event_at!)} WAT
                      </title>
                    </circle>
                  ))}
              </g>
            ))}
            <text x="105" y={competitions.length * 65 + 15}>
              {eventLabel(new Date(min).toISOString())}
            </text>
            <text x="580" y={competitions.length * 65 + 15} textAnchor="end">
              {eventLabel(new Date(max).toISOString())}
            </text>
          </svg>
          <p className="subtle">
            Green profit · red loss. Event markers do not establish run-duration
            overlap.
          </p>
        </>
      ) : (
        <div className="chart-empty">No priced run events yet</div>
      )}
    </section>
  );
}
