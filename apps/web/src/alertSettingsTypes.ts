export type AlertSettings = {
  enabled: boolean;
  email_enabled: boolean;
  slack_enabled: boolean;
  capital: number;
  allowance: number;
  lead_seconds: number;
  league: string;
  next_win_enabled: boolean;
  next_win_qualification: number;
  next_win_attempts: number;
  next_season_enabled: boolean;
  next_season_qualification: number;
  next_season_attempts: number;
};
export type AlertSettingsView = {
  settings: AlertSettings;
  needs_review: boolean;
  profiles: { email: AlertSettings; slack: AlertSettings };
  production_delivery_allowed: boolean;
  channels: Record<
    "email" | "slack",
    { delivery_enabled: boolean; configured: boolean }
  >;
};
