import type { ScenarioSettings } from "./useScenario";

export function StrategyParameters({
  id,
  settings,
  change,
}: {
  id: string;
  settings: ScenarioSettings;
  change: (name: keyof ScenarioSettings, value: number | string) => void;
}) {
  const prefix = id === "champion-next-win" ? "next_win" : "next_season";
  return (
    <div className="simulator-controls" aria-label={`${id} settings`}>
      {(["qualification", "attempts"] as const).map((field) => {
        const key = `${prefix}_${field}` as keyof ScenarioSettings;
        return (
          <label key={key}>
            {field === "qualification" ? "First win day ≥" : "Attempts"}
            <input
              aria-label={`${id} ${field}`}
              type="number"
              min="1"
              max={field === "qualification" ? 30 : 10}
              step="1"
              value={settings[key] ?? settings[field]}
              onChange={(event) => change(key, Number(event.target.value))}
            />
          </label>
        );
      })}
    </div>
  );
}
