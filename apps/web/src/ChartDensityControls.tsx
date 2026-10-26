export function ChartDensityControls({
  mode,
  bucketHours,
  setBucketHours,
  total,
  visibleLimit,
  setVisibleLimit,
}: {
  mode: string;
  bucketHours: number;
  setBucketHours: (value: number) => void;
  total: number;
  visibleLimit: number;
  setVisibleLimit: (value: number) => void;
}) {
  return (
    <div className="chart-density-controls">
      {mode === "Candles" && (
        <label>
          Candle interval{" "}
          <select
            aria-label="Candle interval"
            value={bucketHours}
            onChange={(e) => setBucketHours(Number(e.target.value))}
          >
            <option value={1}>1 hour</option>
            <option value={4}>4 hours</option>
            <option value={24}>1 day</option>
            <option value={168}>7 days</option>
          </select>
        </label>
      )}
      {total > 0 && (
        <label className="density-slider">
          {mode === "Candles" ? "Candles" : "Nodes"}{" "}
          <input
            type="range"
            aria-label="Visible chart nodes"
            min={1}
            max={Math.max(1, total)}
            value={Math.min(visibleLimit, total)}
            onChange={(e) => setVisibleLimit(Number(e.target.value))}
          />
          <output>
            {Math.min(visibleLimit, total)} / {total}
          </output>
        </label>
      )}
      <span className="subtle">
        Latest visible events · fewer nodes make wider marks
      </span>
    </div>
  );
}
