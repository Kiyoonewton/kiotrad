import type { SharedRun } from "./sharedSimulation";
import type { Overview } from "./api";
import { MonitoringRows } from "./MonitoringRows";

type Props = {
  sharedRuns?: SharedRun[];
  rows: NonNullable<Overview["strategy_monitoring"]>;
  strategies: Overview["strategies"];
  competitions: Overview["competitions"];
};

export function MonitoringGroups({
  rows,
  strategies,
  competitions,
  sharedRuns,
}: Props) {
  const groups = [
    ...strategies.map((s) => ({ id: s.id, name: s.name })),
    { id: undefined, name: "Monitoring availability" },
  ];
  return (
    <div className="monitoring-groups">
      {groups.map((group) => {
        const selected = rows.filter((r) => r.strategy_id === group.id);
        if (!selected.length) return null;
        selected.sort(
          (a, b) =>
            competitions.findIndex((c) => c.id === a.competition) -
            competitions.findIndex((c) => c.id === b.competition),
        );
        return (
          <section key={group.id ?? "availability"} aria-label={group.name}>
            <h3 className="monitoring-group-title">{group.name}</h3>
            <MonitoringRows
              rows={selected}
              sharedRuns={sharedRuns}
              strategies={strategies}
              competitions={competitions}
            />
          </section>
        );
      })}
      {!rows.length && (
        <p className="subtle">
          No monitoring observations returned for this selection.
        </p>
      )}
    </div>
  );
}
