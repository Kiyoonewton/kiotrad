import { naira } from "./api";
import type { SharedSimulation } from "./sharedSimulation";

export function TimingSensitivityPanel({ data }: { data: SharedSimulation }) {
  if (!data.settlement_model) return null;
  return (
    <section className="quiet-panel" aria-label="Settlement timing model">
      <p>
        Historical replay · kickoff +{data.settlement_model.seconds}s settlement
        model. Recorded results and prices are used; finish times are assumed.
      </p>
      <details>
        <summary>
          Timing sensitivity · {data.timing_sensitive
            ? "allocation or capital changes across scenarios"
            : "same allocation and capital across scenarios"}
        </summary>
        <div className="shared-run-table">
          <table>
            <thead><tr><th>Model</th><th>Capital</th><th>Completed</th><th>Overlap exclusions</th><th>Allocation</th></tr></thead>
            <tbody>{data.timing_sensitivity?.map((s) => (
              <tr key={s.settlement_seconds}>
                <td>+{s.settlement_seconds}s{s.settlement_seconds === data.settlement_model!.seconds ? " · primary" : ""}</td>
                <td>{naira(s.balance_minor)}</td><td>{s.completed_runs}</td><td>{s.overlap_exclusions}</td>
                <td>{s.allocation_changed_vs_primary ? "Different" : "Same as primary"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
        {data.timing_sensitivity?.filter((s) => s.allocation_changed_vs_primary).map((s) => (
          <details key={s.settlement_seconds}>
            <summary>+{s.settlement_seconds}s · changed run selections</summary>
            <p>Only in this scenario: {s.accepted_only_in_scenario.join(", ") || "None"}</p>
            <p>Only in primary: {s.accepted_only_in_primary.join(", ") || "None"}</p>
          </details>
        ))}
      </details>
    </section>
  );
}
