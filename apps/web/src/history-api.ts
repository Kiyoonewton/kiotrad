import type { Overview } from "./api";

export interface SimulationPage {
  rows: NonNullable<Overview["strategy_monitoring"]>;
  pagination: {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    unit: string;
  };
}

export async function getSimulationHistory(
  page: number,
  competition: string,
  signal: AbortSignal,
  date = "",
): Promise<SimulationPage> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: "10",
    competition,
  });
  if (date) {
    params.set("from", date);
    params.set("to", date);
  }
  const response = await fetch(`/api/v1/simulations?${params}`, {
    signal,
    headers: { Accept: "application/json" },
  });
  if (!response.ok)
    throw new Error("Simulation history is unavailable. Please retry.");
  const data = await response.json();
  if (
    data.schema_version !== "1" ||
    !Array.isArray(data.rows) ||
    !data.pagination
  ) {
    throw new Error("Simulation history response is incompatible.");
  }
  return data;
}
