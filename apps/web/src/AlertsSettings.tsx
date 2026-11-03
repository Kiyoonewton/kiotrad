import { useState, type FormEvent } from "react";
import { AlertAllowance } from "./AlertAllowance";
import { AlertDeliveryChannels } from "./AlertDeliveryChannels";
import type { AlertSettings, AlertSettingsView } from "./alertSettingsTypes";
import "./history.css";

export function AlertsSettings() {
  const [token, setToken] = useState("");
  const [saved, setSaved] = useState<AlertSettingsView | null>(null);
  const [draft, setDraft] = useState<AlertSettings | null>(null);
  const [source, setSource] = useState<"email" | "slack" | "">("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const call = async (save: boolean) => {
    setBusy(true);
    setMessage("");
    try {
      const response = await fetch("/api/v1/notifications/alert-settings", {
        method: save ? "PUT" : "GET",
        cache: "no-store",
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
          ...(save ? { "Content-Type": "application/json" } : {}),
        },
        ...(save
          ? {
              body: JSON.stringify({
                ...draft,
                ...(source ? { migration_source: source } : {}),
              }),
            }
          : {}),
      });
      if (!response.ok)
        throw new Error(
          response.status === 403
            ? "Settings key required or not accepted."
            : response.status === 422
              ? "Check the settings and choose an existing profile if requested."
              : "Unable to load or save alerts. Retry.",
        );
      const result = (await response.json()) as AlertSettingsView;
      setSaved(result);
      setDraft(result.settings);
      setSource("");
      setMessage(
        save
          ? "Saved. Both channels use these parameters on their next scan."
          : "Settings loaded.",
      );
    } catch (error) {
      setMessage(
        error instanceof Error ? error.message : "Alerts unavailable.",
      );
    } finally {
      setBusy(false);
    }
  };
  const change = <K extends keyof AlertSettings>(
    key: K,
    value: AlertSettings[K],
  ) =>
    setDraft((previous) =>
      previous ? { ...previous, [key]: value } : previous,
    );
  const submit = (event: FormEvent) => {
    event.preventDefault();
    void call(!!saved);
  };
  const choose = (channel: "email" | "slack") => {
    if (!saved || !draft) return;
    const { enabled: _enabled, ...parameters } = saved.profiles[channel];
    setDraft({
      ...draft,
      ...parameters,
      enabled: draft.enabled,
      email_enabled: draft.email_enabled,
      slack_enabled: draft.slack_enabled,
    });
    setSource(channel);
  };
  return (
    <section className="history-page" aria-label="Alert settings">
      <header className="section-heading">
        <div>
          <p className="eyebrow">Manual betting alerts</p>
          <h1>Alerts</h1>
          <p className="subtle">One set of parameters · Email and Slack</p>
        </div>
      </header>
      {saved && !saved.production_delivery_allowed && (
        <p role="status" className="message-panel">
          Delivery is production-only. Local and test environments cannot send
          alerts.
        </p>
      )}
      <form onSubmit={submit}>
        {!saved ? (
          <div className="history-filters">
            <label className="history-search">
              Settings key
              <input
                type="password"
                autoComplete="off"
                required
                minLength={32}
                value={token}
                onChange={(e) => setToken(e.target.value)}
              />
            </label>
            <button type="submit" disabled={busy}>
              {busy ? "Loading…" : "Unlock settings"}
            </button>
          </div>
        ) : (
          draft && (
            <>
              <AlertDeliveryChannels
                draft={draft}
                view={saved}
                change={change}
              />
              {saved.needs_review && (
                <div
                  className="message-panel"
                  role="group"
                  aria-label="Existing alert profiles"
                >
                  <p>
                    Email and Slack have different parameters. Choose a starting
                    profile, review it, then save for both.
                  </p>
                  <div className="browse-toolbar">
                    {(["email", "slack"] as const).map((channel) => (
                      <button
                        type="button"
                        key={channel}
                        aria-pressed={source === channel}
                        onClick={() => choose(channel)}
                      >
                        Use {channel === "email" ? "Email" : "Slack"} · ₦
                        {saved.profiles[channel].capital.toLocaleString(
                          "en-NG",
                        )}
                      </button>
                    ))}
                  </div>
                </div>
              )}
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
                    required
                    min={100}
                    max={10000000}
                    step={1}
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
                    required
                    min={5}
                    max={600}
                    step={1}
                    value={draft.lead_seconds}
                    onChange={(e) =>
                      change("lead_seconds", Number(e.target.value))
                    }
                  />
                </label>
              </div>
              <AlertAllowance
                value={draft.allowance}
                change={(value) => change("allowance", value)}
              />
              {(["next_win", "next_season"] as const).map((prefix) => (
                <fieldset className="history-filters" key={prefix}>
                  <legend>
                    {prefix === "next_win" ? "Next Win" : "Next Season"}
                  </legend>
                  <label>
                    Include strategy
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
                      required
                      min={1}
                      max={30}
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
                      required
                      min={1}
                      max={10}
                      value={draft[`${prefix}_attempts`]}
                      onChange={(e) =>
                        change(`${prefix}_attempts`, Number(e.target.value))
                      }
                    />
                  </label>
                </fieldset>
              ))}
              <p className="subtle">
                Frozen conditional stake plans use saved capital and allowance.
                Future rows use historical estimates; confirm current odds and
                funds manually.
              </p>
              <div className="browse-toolbar" style={{ marginTop: 24 }}>
                <button
                  type="submit"
                  disabled={busy || (saved.needs_review && !source)}
                >
                  {busy ? "Saving…" : "Save alert settings"}
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setToken("");
                    setSaved(null);
                    setDraft(null);
                    setSource("");
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
