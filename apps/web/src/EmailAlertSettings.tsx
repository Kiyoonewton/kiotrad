import { useState, type FormEvent } from "react";
import "./history.css";
import "./simulator.css";

type AlertSettings = {
  enabled: boolean;
  capital: number;
  lead_seconds: number;
  league: string;
  next_win_enabled: boolean;
  next_win_qualification: number;
  next_win_attempts: number;
  next_season_enabled: boolean;
  next_season_qualification: number;
  next_season_attempts: number;
};
type SettingsResponse = {
  settings: AlertSettings;
  delivery_enabled: boolean;
  smtp_configured: boolean;
};
export function EmailAlertSettings() {
  const [token, setToken] = useState("");
  const [saved, setSaved] = useState<SettingsResponse | null>(null);
  const [draft, setDraft] = useState<AlertSettings | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const call = async (save: boolean) => {
    setBusy(true);
    setMessage("");
    try {
      const response = await fetch("/api/v1/notifications/email-settings", {
        method: save ? "PUT" : "GET",
        cache: "no-store",
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
          ...(save ? { "Content-Type": "application/json" } : {}),
        },
        ...(save ? { body: JSON.stringify(draft) } : {}),
      });
      if (!response.ok)
        throw new Error(
          response.status === 403
            ? "Settings key required or not accepted."
            : response.status === 422
              ? "Check capital, timing and strategy ranges."
              : "Unable to save or load email settings. Retry.",
        );
      const result = (await response.json()) as SettingsResponse;
      setSaved(result);
      setDraft(result.settings);
      setMessage(
        save
          ? "Saved. The next worker scan uses these settings."
          : "Settings loaded.",
      );
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Email settings unavailable.",
      );
    } finally {
      setBusy(false);
    }
  };
  const change = <K extends keyof AlertSettings>(
    key: K,
    value: AlertSettings[K],
  ) => setDraft((s) => (s ? { ...s, [key]: value } : s));
  const submit = (event: FormEvent) => {
    event.preventDefault();
    void call(!!saved);
  };
  return (
    <section className="history-page" aria-label="Email alert settings">
      <header className="section-heading">
        <div>
          <p className="eyebrow">Manual betting alerts</p>
          <h1>Email alerts</h1>
          <p className="subtle">
            Separate from simulator settings. No automatic bets.
          </p>
        </div>
      </header>
      <form onSubmit={submit}>
        {!saved ? (
          <div className="history-filters">
            <label className="history-search">
              Settings key
              <input
                type="password"
                autoComplete="off"
                value={token}
                onChange={(e) => setToken(e.target.value)}
                required
                minLength={32}
              />
            </label>
            <button disabled={busy} type="submit">
              {busy ? "Loading…" : "Unlock settings"}
            </button>
          </div>
        ) : (
          draft && (
            <>
              <div
                className="history-summary"
                aria-label="Email delivery status"
              >
                <div>
                  <small>Alerts</small>
                  <strong>
                    {saved.settings.enabled ? "Enabled" : "Paused"}
                  </strong>
                </div>
                <div>
                  <small>Delivery switch</small>
                  <strong>
                    {saved.delivery_enabled ? "On" : "Off in server env"}
                  </strong>
                </div>
                <div>
                  <small>SMTP</small>
                  <strong>
                    {saved.smtp_configured ? "Configured" : "Setup required"}
                  </strong>
                </div>
                <div>
                  <small>Saved budget</small>
                  <strong>
                    ₦{saved.settings.capital.toLocaleString("en-NG")}
                  </strong>
                </div>
              </div>
              <div className="history-filters" style={{ marginTop: 24 }}>
                <label>
                  Alerts
                  <select
                    value={draft.enabled ? "on" : "off"}
                    onChange={(e) => change("enabled", e.target.value === "on")}
                  >
                    <option value="on">Enabled</option>
                    <option value="off">Paused</option>
                  </select>
                </label>
                <label>
                  Total run capital · ₦
                  <input
                    type="number"
                    min={100}
                    max={10000000}
                    step={1}
                    required
                    value={draft.capital}
                    onChange={(e) => change("capital", Number(e.target.value))}
                  />
                </label>
                <label>
                  League
                  <select
                    value={draft.league}
                    onChange={(e) => change("league", e.target.value)}
                  >
                    <option value="all">All leagues</option>
                    <option value="virtual-football">English</option>
                    <option value="virtual-bundesliga">Bundesliga</option>
                    <option value="virtual-euro">Euro</option>
                  </select>
                </label>
                <label>
                  Minimum lead · seconds
                  <input
                    type="number"
                    min={5}
                    max={600}
                    step={1}
                    required
                    value={draft.lead_seconds}
                    onChange={(e) =>
                      change("lead_seconds", Number(e.target.value))
                    }
                  />
                </label>
              </div>
              {(["next_win", "next_season"] as const).map((prefix) => (
                <fieldset className="history-filters" key={prefix}>
                  <legend>
                    {prefix === "next_win" ? "Next Win" : "Next Season"}
                  </legend>
                  <label>
                    Send alerts
                    <select
                      value={draft[`${prefix}_enabled`] ? "on" : "off"}
                      onChange={(e) =>
                        change(`${prefix}_enabled`, e.target.value === "on")
                      }
                    >
                      <option value="on">Yes</option>
                      <option value="off">No</option>
                    </select>
                  </label>
                  <label>
                    First win day ≥
                    <input
                      aria-label={`${prefix} qualification`}
                      type="number"
                      min={1}
                      max={30}
                      required
                      value={draft[`${prefix}_qualification`]}
                      onChange={(e) =>
                        change(
                          `${prefix}_qualification`,
                          Number(e.target.value),
                        )
                      }
                    />
                  </label>
                  <label>
                    Attempts maximum
                    <input
                      aria-label={`${prefix} attempts`}
                      type="number"
                      min={1}
                      max={10}
                      required
                      value={draft[`${prefix}_attempts`]}
                      onChange={(e) =>
                        change(`${prefix}_attempts`, Number(e.target.value))
                      }
                    />
                  </label>
                </fieldset>
              ))}
              <p className="subtle">
                Emails include your run budget and attempt limit. Live stake
                amounts are not calculated. Lead time is measured before
                scheduled kickoff; delivery and bookmaker cutoff may differ.
              </p>
              <div className="browse-toolbar" style={{ marginTop: 24 }}>
                <button type="submit" disabled={busy}>
                  {busy ? "Saving…" : "Save email settings"}
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setSaved(null);
                    setDraft(null);
                    setToken("");
                    setMessage("");
                  }}
                >
                  Lock settings
                </button>
              </div>
            </>
          )
        )}
      </form>
      {message && (
        <p role="status" className="message-panel">
          {message}
        </p>
      )}
    </section>
  );
}
