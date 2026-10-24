export interface Strategy {
  id: string;
  name: string;
  version: string;
  status: string;
  monitoring_enabled: boolean;
  bankroll_enabled: boolean;
  description: string;
  entry: string;
  rules: {
    max_attempts: number;
    minimum_stake_minor: number;
    profit_per_attempt_minor: number;
  };
  unresolved: string[];
}

export interface Overview {
  schema_version: string;
  mode: string;
  currency: string;
  display_timezone: string;
  competitions: {
    id: string;
    name: string;
    feed_status: string;
    season_id?: string | null;
    matchday?: number | null;
    last_success_at?: string | null;
    settled_count?: number | null;
    fixture_count?: number | null;
    market_observation?: string;
    unresolved_seasons?: string[];
    latest_champion?: { team_name: string; season_id: string; confirmed_at: string } | null;
  }[];
  strategies: Strategy[];
}

export async function getOverview(signal: AbortSignal): Promise<Overview> {
  const response = await fetch("/api/v1/overview", {
    signal,
    headers: { Accept: "application/json" },
  });
  if (!response.ok)
    throw new Error(
      "The API is unavailable. Check that the API service is running.",
    );
  const data: unknown = await response.json();
  if (
    !data ||
    typeof data !== "object" ||
    !("schema_version" in data) ||
    data.schema_version !== "1" ||
    !("competitions" in data) ||
    !Array.isArray(data.competitions) ||
    !("strategies" in data) ||
    !Array.isArray(data.strategies)
  ) {
    throw new Error("The API response is incompatible with this dashboard.");
  }
  return data as Overview;
}

export function naira(minor: number): string {
  return new Intl.NumberFormat("en-NG", {
    style: "currency",
    currency: "NGN",
    maximumFractionDigits: 0,
  }).format(minor / 100);
}
