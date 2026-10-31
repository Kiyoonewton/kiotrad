import type { SharedSimulation } from "./sharedSimulation";
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

export interface PaperAttempt {
  attempt: number;
  matchday: number;
  stake_minor: number;
  odds: string;
  status: string;
  potential_payout_minor: number;
  payout_minor?: number;
  scheduled_start_at: string;
}
export interface PaperRun {
  id: string;
  competition: string;
  strategy_id: string;
  season_id: string;
  team: string;
  status: string;
  net_minor: number;
  attempts: PaperAttempt[];
}
export interface RunCalculation {
  mode: string;
  status: string;
  starting_capital_minor: number;
  total_staked_minor: number;
  gross_payout_minor: number;
  realized_net_minor: number;
  net_minor: number | null;
  unpriced_matchday?: number;
  data_quality_notes?: string[];
  attempts: (Omit<PaperAttempt, "payout_minor"> & {
    home_team: string;
    away_team: string;
    result: string | null;
    payout_minor: number | null;
  })[];
}
export interface Overview {
  calendar_coverage?: {
    status: string;
    leagues: {
      competition: string;
      dates: string[];
      days?: { date: string; first_at: string; last_at: string }[];
      first_at: string | null;
      last_at: string | null;
      settled_fixtures: number;
      unknown_timing_fixtures: number;
    }[];
  };
  local_sync?: { database?: string; completed_at: string; captures: number; mode: string } | null;
  dashboard_cache?: {
    status: "warming" | "ready" | "stale" | "unavailable";
    generated_at: string | null;
  };
  shared_simulation?: SharedSimulation;
  research_simulation?: {
    status: string;
    from?: string | null;
    scope?: string;
    seasons_assessed?: number;
    to?: string;
    generated_at?: string;
    starting_capital_per_run_minor?: number;
    runs: {
      competition: string;
      season_id: string;
      strategy_id: string;
      team: string;
      status: string;
      net_minor: number | null;
      total_staked_minor: number;
      gross_payout_minor: number;
      event_at: string | null;
      started_at?: string | null;
      run_kind?: "selected" | "skipped";
      observed_result?: "win" | "no_win" | "pending";
      overlap_with?: string;
      overlap_basis?: string;
      result_observed_at?: string | null;
      span_basis?: string;
      attempts?: number;
    }[];
  };
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
    collection_gap?: { expected_at: string; detected_at: string } | null;
    last_collection_gap?: {
      expected_at: string;
      resumed_at: string;
      pending_season_ids: string[];
      reconciliation_status: string;
    } | null;
    latest_champion?: {
      team_name: string;
      season_id: string;
      confirmed_at: string;
    } | null;
  }[];
  strategy_monitoring?: {
    competition: string;
    strategy_id?: string;
    season_id?: string;
    reason?: string;
    status: string;
    team?: string | null;
    trigger_day?: number | null;
    attempt?: number | null;
    simulation?: RunCalculation | null;
    recovered?: boolean;
    data_quality_notes?: string[];
    next_match?: {
      matchday: number;
      home_team: string;
      away_team: string;
    } | null;
  }[];
  bankroll?: {
    status: string;
    balance_minor: number | null;
    available_minor?: number | null;
    starting_balance_minor?: number | null;
    committed_minor?: number | null;
    net_profit_minor?: number | null;
    target_balance_minor?: number | null;
    next_milestone_minor?: number | null;
    milestone_step_minor?: number | null;
    milestones_reached?: number;
  };
  active_run?: PaperRun | null;
  capital_history?: { at: string; balance_minor: number }[];
  recent_runs?: PaperRun[];
  paper_journal_full?: boolean;
  paper_worker?: {
    status: string;
    observed_at?: string | null;
    next_check_at?: string | null;
    last_result?: string | null;
  };
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
    maximumFractionDigits: 2,
  }).format(minor / 100);
}
