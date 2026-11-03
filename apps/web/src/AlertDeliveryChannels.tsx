import type { AlertSettings, AlertSettingsView } from "./alertSettingsTypes";
export function AlertDeliveryChannels({
  draft,
  view,
  change,
}: {
  draft: AlertSettings;
  view: AlertSettingsView;
  change: (key: "email_enabled" | "slack_enabled", value: boolean) => void;
}) {
  return (
    <div
      className="history-summary alert-delivery-channels"
      aria-label="Delivery channels"
    >
      {(["email", "slack"] as const).map((channel) => (
        <label key={channel}>
          <strong>{channel === "email" ? "Email" : "Slack"}</strong>
          <select
            aria-label={`${channel} delivery`}
            value={draft[`${channel}_enabled`] ? "on" : "off"}
            onChange={(e) =>
              change(`${channel}_enabled`, e.target.value === "on")
            }
          >
            <option value="on">Enabled</option>
            <option value="off">Off</option>
          </select>
          <small>
            {view.channels[channel].configured
              ? "Configured"
              : "Setup required"}{" "}
            ·{" "}
            {view.channels[channel].delivery_enabled
              ? "Server delivery on"
              : "Server delivery off"}
          </small>
        </label>
      ))}
    </div>
  );
}
