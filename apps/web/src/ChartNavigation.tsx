import type { useChartNavigation } from "./useChartNavigation";

export function ChartNavigation({
  navigation,
  total,
}: {
  navigation: ReturnType<typeof useChartNavigation>;
  total: number;
}) {
  return (
    <div className="chart-navigation">
      <div className="chart-modes" aria-label="Chart zoom controls">
        <button
          aria-label="Zoom chart out"
          onClick={() => navigation.zoom(false)}
        >
          −
        </button>
        <button
          aria-label="Zoom chart in"
          onClick={() => navigation.zoom(true)}
        >
          +
        </button>
        <button onClick={navigation.fit}>Fit history</button>
      </div>
      <label>
        Slide history{" "}
        <input
          aria-label="Pan chart history"
          type="range"
          min="0"
          max={navigation.maxOffset}
          value={navigation.start}
          onChange={(e) => navigation.pan(Number(e.target.value))}
        />
      </label>
      <small>
        {navigation.count
          ? `${navigation.start + 1}–${navigation.start + navigation.count}`
          : "0"}{" "}
        / {total} · drag chart to pan
      </small>
    </div>
  );
}
