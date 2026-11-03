import {
  emptyHistorySelection,
  type HistorySelection,
} from "./historySelection";
export function HistoryFilters({
  value,
  change,
}: {
  value: HistorySelection;
  change: (value: HistorySelection) => void;
}) {
  const set = (key: keyof HistorySelection, next: string) =>
    change({ ...value, [key]: next });
  return (
    <div className="history-filters" role="group" aria-label="History filters">
      <label>
        From · WAT
        <input
          type="date"
          value={value.from}
          onChange={(e) => set("from", e.target.value)}
        />
      </label>
      <label>
        To · WAT
        <input
          type="date"
          value={value.to}
          min={value.from || undefined}
          onChange={(e) => set("to", e.target.value)}
        />
      </label>
      <label>
        Strategy
        <select
          value={value.strategy}
          onChange={(e) => set("strategy", e.target.value)}
        >
          <option value="all">Both strategies</option>
          <option value="champion-next-win">Next Win</option>
          <option value="champion-next-season">Next Season</option>
        </select>
      </label>
      <label>
        Result
        <select
          value={value.result}
          onChange={(e) => set("result", e.target.value)}
        >
          <option value="all">All results</option>
          <option value="settled">Settled</option>
          <option value="win">Won</option>
          <option value="loss">Lost</option>
          <option value="skipped">Skipped</option>
          <option value="pending">Pending</option>
          <option value="not_qualified">Not qualified</option>
        </select>
      </label>
      <label className="history-search">
        Team or season
        <input
          type="search"
          placeholder="Search history…"
          value={value.search}
          onChange={(e) => set("search", e.target.value)}
        />
      </label>
      <button
        className="history-reset"
        onClick={() => change(emptyHistorySelection)}
      >
        Reset filters
      </button>
    </div>
  );
}
