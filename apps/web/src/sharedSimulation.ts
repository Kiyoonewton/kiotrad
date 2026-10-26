export type SharedRun = {
  id: string;
  competition?: string;
  season_id?: string;
  strategy_id?: string;
  team?: string;
  entry_at: string;
  released_at?: string | null;
  status: string;
  blocked_by?: string;
  overlap_basis?: string;
  timing_warning?: string | null;
  result_observed_at?: string | null;
  simulation_settlement_at?: string | null;
  observed_window?: {
    result: "win" | "no_win" | "pending";
    ended_at: string | null;
  };
  fixtures?: { scheduled_start_at: string; won?: boolean | null }[];
  skip_reason?: string;
  missing_historical_fixtures?: {
    matchday: number;
    home_team?: string;
    away_team?: string;
    outcome?: string;
    provider_match_id?: string;
  }[];
  net_minor?: number | null;
  total_staked_minor?: number;
  gross_payout_minor?: number;
  capital_at_entry_minor?: number;
  estimate?: {
    allowance_minor: number;
    allowance_multiplier?: number;
    plan: { total_minor: number; profit_per_attempt_minor: number };
  };
  attempts?: {
    home_team?: string;
    away_team?: string;
    actual_odds_scaled?: number;
    attempt: number;
    matchday: number;
    stake_minor: number;
    target_profit_minor: number;
    payout_minor: number | null;
    scheduled_start_at?: string;
  }[];
};
export type SharedSimulation = {
  status: string;
  starting_capital_minor: number;
  balance_minor: number;
  reserved_allowance_minor: number;
  active_staked_minor: number;
  active_run_id: string | null;
  seasons_assessed: number;
  runs: SharedRun[];
  excluded?: { reason: string }[];
  timing_basis: string;
  timing_quality?: string;
  settlement_model?: { seconds: number; version: string; basis: string };
  timing_sensitive?: boolean;
  timing_sensitivity?: {
    settlement_seconds: number;
    balance_minor: number;
    completed_runs: number;
    overlap_exclusions: number;
    allocation_changed_vs_primary: boolean;
    accepted_only_in_scenario: string[];
    accepted_only_in_primary: string[];
  }[];
  recovery_target_minor?: number | null;
  allowance_multiplier?: number;
};
