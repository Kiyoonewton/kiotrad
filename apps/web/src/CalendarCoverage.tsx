import type { Overview } from "./api";

export function CalendarCoverage({
  data,
  league,
}: {
  data: Overview;
  league: string;
}) {
  const coverage = data.calendar_coverage;
  if (!coverage) return null;
  if (coverage.status !== "available")
    return <p role="status">Calendar coverage unavailable</p>;
  const rows = coverage.leagues.filter(
    (r) => league === "all" || r.competition === league,
  );
  const dates = [...new Set(rows.flatMap((r) => r.dates))].sort();
  const starts = rows
    .flatMap((r) => [r.first_at, r.last_at])
    .filter((v): v is string => !!v)
    .sort();
  const first = starts[0],
    last = starts.at(-1);
  const span =
    first && last ? (Date.parse(last) - Date.parse(first)) / 86400000 : 0;
  const missing = dates.length
    ? Math.round(
        (Date.parse(dates.at(-1)!) - Date.parse(dates[0])) / 86400000,
      ) +
      1 -
      dates.length
    : 0;
  const unknown = rows.reduce((n, r) => n + r.unknown_timing_fixtures, 0);
  const format = (v: string) =>
    new Date(v).toLocaleString("en-GB", { timeZone: "Africa/Lagos" });
  return (
    <section className="quiet-panel" aria-label="Calendar data coverage">
      <p className="eyebrow">Real calendar time · recorded settled fixtures</p>
      <h2>{dates.length} calendar days with data</h2>
      <p>
        {first && last
          ? `${format(first)} → ${format(last)} WAT · ${span.toFixed(2)} elapsed 24-hour days`
          : "No settled fixtures with known timing"}
      </p>
      <p>
        {missing} dates without timed records within this span ·{" "}
        {unknown.toLocaleString()} fixtures without timing
      </p>
      <details>
        <summary>Inspect recorded dates</summary>
        <p>{dates.join(" · ") || "None"}</p>
      </details>
      <small>
        Counts use stored scheduled kickoff dates in Lagos, across all settled
        fixtures, including seasons without qualifying runs. A date with data
        does not imply complete coverage. Untimed history is excluded.
      </small>
    </section>
  );
}
