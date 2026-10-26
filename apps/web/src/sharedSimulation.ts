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
    plan: { total_minor: number; profit_per_attempt_minor: number };
  };
  attempts?: {
    attempt: number;
    matchday: number;
    stake_minor: number;
    target_profit_minor: number;
    payout_minor: number | null;
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
};
