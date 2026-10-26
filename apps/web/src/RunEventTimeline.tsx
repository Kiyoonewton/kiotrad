import { useRef, useState } from "react";
import type { Overview } from "./api";
import { useTimelineWidth } from "./useTimelineWidth";
import { eventLabel } from "./researchChartData";

type Run = NonNullable<Overview["research_simulation"]>["runs"][number];
const key = (r: Run) => `${r.competition}:${r.season_id}:${r.strategy_id}`;
const names: Record<string, string> = {
  "champion-next-win": "Next Win",
  "champion-next-season": "Next Season",
};

export function RunEventTimeline({
  data,
  competitions,
}: {
  data: Overview["research_simulation"];
  competitions: Overview["competitions"];
}) {
  const [level, setLevel] = useState(0);
  const [selected, setSelected] = useState<string | null>(null);
  const viewport = useRef<HTMLDivElement>(null);
  const drag = useRef<{ x: number; left: number } | null>(null);
  const events = (data?.runs ?? []).filter(
    (r) =>
      Number.isFinite(Date.parse(r.event_at ?? "")) &&
      r.net_minor !== null &&
      competitions.some((c) => c.id === r.competition),
  );
  const strategies = [...new Set(events.map((r) => r.strategy_id))].sort();
  const lanes = competitions.flatMap((c) =>
    strategies.map((s) => ({
      id: `${c.id}:${s}`,
      competition: c,
      strategy: s,
    })),
  );
  const times = events
    .flatMap((r) => [
      Date.parse(r.event_at!),
      Date.parse(r.started_at ?? r.event_at!),
    ])
    .filter(Number.isFinite);
  const min = times.length ? Math.min(...times) : 0;
  const max = times.length ? Math.max(...times) : 1;
  const zoom = 2 ** level;
  const availableWidth = useTimelineWidth(viewport, events.length);
  const width = availableWidth * zoom;
  const left = 12;
  const x = (time: number) =>
    left + ((time - min) / Math.max(1, max - min)) * (width - left - 30);
  const active = events.find((r) => key(r) === selected);
  function changeZoom(next: number) {
    const element = viewport.current;
    const center = element
      ? (element.scrollLeft + element.clientWidth / 2) / width
      : 0;
    setLevel(next);
    requestAnimationFrame(() => {
      if (element)
        element.scrollLeft =
          center * (availableWidth * 2 ** next) - element.clientWidth / 2;
    });
  }
  return (
    <section
      className="quiet-panel run-event-panel"
      aria-labelledby="run-map-title"
    >
      <div className="timeline-heading">
        <div>
          <p className="eyebrow">Across the leagues</p>
          <h2 id="run-map-title">Simulation run map</h2>
        </div>
        <label className="timeline-zoom">
          Zoom · {zoom}×
          <input
            aria-label="Run timeline zoom"
            type="range"
            min="0"
            max="7"
            value={level}
            onChange={(e) => changeZoom(Number(e.target.value))}
          />
        </label>
        <button
          className="timeline-reset"
          onClick={() => {
            setLevel(0);
            if (viewport.current) viewport.current.scrollLeft = 0;
          }}
        >
          Fit all
        </button>
      </div>
      <p className="subtle">
        First → final scheduled attempt · WAT · drag or scroll sideways
      </p>
      {events.length ? (
        <>
          <div className="run-timeline-layout">
            <div className="run-timeline-labels" aria-label="Timeline lanes">
              {lanes.map((lane) => (
                <div key={lane.id}>
                  <strong>{lane.competition.name}</strong>
                  <span>{names[lane.strategy] ?? lane.strategy}</span>
                </div>
              ))}
            </div>
            <div
              className="run-timeline-viewport"
              ref={viewport}
              tabIndex={0}
              role="region"
              aria-label="Scrollable run timeline"
              onPointerDown={(e) => {
                if (
                  e.button !== 0 ||
                  (e.target as Element).closest("[data-run]")
                )
                  return;
                drag.current = {
                  x: e.clientX,
                  left: e.currentTarget.scrollLeft,
                };
                e.currentTarget.setPointerCapture(e.pointerId);
              }}
              onPointerMove={(e) => {
                if (drag.current)
                  e.currentTarget.scrollLeft =
                    drag.current.left + drag.current.x - e.clientX;
              }}
              onPointerUp={() => {
                drag.current = null;
              }}
              onPointerCancel={() => {
                drag.current = null;
              }}
              onKeyDown={(e) => {
                if (e.target !== e.currentTarget) return;
                if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
                  e.preventDefault();
                  e.currentTarget.scrollLeft +=
                    e.key === "ArrowRight" ? 180 : -180;
                }
              }}
            >
              <svg
                width={width}
                height={lanes.length * 54 + 55}
                viewBox={`0 0 ${width} ${lanes.length * 54 + 55}`}
                role="img"
                aria-label="Scheduled run spans by league and strategy"
              >
                {lanes.map((lane, i) => (
                  <g key={lane.id}>
                    <line
                      x1={left}
                      x2={width - 30}
                      y1={i * 54 + 20}
                      y2={i * 54 + 20}
                      stroke="#34443d"
                    />
                    {events
                      .filter(
                        (r) =>
                          r.competition === lane.competition.id &&
                          r.strategy_id === lane.strategy,
                      )
                      .map((r) => {
                        const end = Date.parse(r.event_at!);
                        const parsed = Date.parse(r.started_at ?? "");
                        const start =
                          Number.isFinite(parsed) && parsed <= end
                            ? parsed
                            : end;
                        const a = x(start),
                          b = x(end),
                          y = i * 54 + 20;
                        return (
                          <g
                            key={key(r)}
                            data-run={key(r)}
                            role="button"
                            tabIndex={0}
                            aria-label={`${lane.competition.name} ${names[r.strategy_id] ?? r.strategy_id} ${r.team} season ${r.season_id}`}
                            onClick={() => setSelected(key(r))}
                            onKeyDown={(e) => {
                              if (e.key === "Enter" || e.key === " ") {
                                e.preventDefault();
                                setSelected(key(r));
                              }
                            }}
                            className={
                              selected === key(r)
                                ? "timeline-run selected"
                                : "timeline-run"
                            }
                          >
                            <title>
                              {r.team} ·{" "}
                              {eventLabel(new Date(start).toISOString())} →{" "}
                              {eventLabel(r.event_at!)} WAT
                            </title>
                            <line
                              x1={a}
                              x2={b}
                              y1={y}
                              y2={y}
                              stroke={r.net_minor! >= 0 ? "#81c9a0" : "#e88f86"}
                              strokeWidth="6"
                              strokeLinecap="round"
                            />
                            <line
                              x1={a}
                              x2={a}
                              y1={y - 6}
                              y2={y + 6}
                              stroke="#dce6df"
                            />
                            <line
                              x1={b}
                              x2={b}
                              y1={y - 6}
                              y2={y + 6}
                              stroke="#dce6df"
                            />
                            <rect
                              x={a - 5}
                              y={y - 12}
                              width={Math.max(10, b - a + 10)}
                              height="24"
                              fill="transparent"
                            />
                          </g>
                        );
                      })}
                  </g>
                ))}
                {Array.from({ length: 7 * zoom + 1 }, (_, i) => {
                  const time = min + ((max - min) * i) / (7 * zoom);
                  return (
                    <text
                      key={i}
                      x={x(time)}
                      y={lanes.length * 54 + 25}
                      textAnchor={i === 7 * zoom ? "end" : "start"}
                    >
                      {eventLabel(new Date(time).toISOString())}
                    </text>
                  );
                })}
              </svg>
            </div>
          </div>
          {active && (
            <div className="timeline-inspector" aria-live="polite">
              <strong>
                {active.team} ·{" "}
                {names[active.strategy_id] ?? active.strategy_id}
              </strong>
              <span>
                Season {active.season_id} · {active.attempts ?? "—"} attempts
              </span>
              <span>
                {eventLabel(active.started_at ?? active.event_at!)} →{" "}
                {eventLabel(active.event_at!)} WAT
              </span>
              <span>
                {new Intl.NumberFormat("en-NG", {
                  style: "currency",
                  currency: "NGN",
                }).format(active.net_minor! / 100)}{" "}
                net
              </span>
            </div>
          )}
          <p className="subtle timeline-legend">
            Green profit · red loss · single-attempt runs are ticks. Scheduled
            spans do not establish settlement-time overlap.
          </p>
        </>
      ) : (
        <div className="chart-empty">No priced run events yet</div>
      )}
    </section>
  );
}
