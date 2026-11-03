import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { AlertsSettings } from "./AlertsSettings";
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const settings = {
  enabled: true,
  email_enabled: true,
  slack_enabled: true,
  capital: 100000,
  allowance: 1,
  lead_seconds: 10,
  league: "all",
  next_win_enabled: true,
  next_win_qualification: 5,
  next_win_attempts: 4,
  next_season_enabled: true,
  next_season_qualification: 5,
  next_season_attempts: 4,
};
const view = {
  settings,
  needs_review: false,
  profiles: { email: { ...settings, capital: 50000 }, slack: settings },
  production_delivery_allowed: true,
  channels: {
    email: { configured: true, delivery_enabled: true },
    slack: { configured: true, delivery_enabled: true },
  },
};
async function unlock() {
  fireEvent.change(screen.getByLabelText("Settings key"), {
    target: { value: "a".repeat(32) },
  });
  fireEvent.click(screen.getByRole("button", { name: "Unlock settings" }));
  await screen.findByLabelText("Total run capital · ₦");
}
it("edits one shared set and independent delivery switches in an explicit atomic save", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({ ok: true, json: async () => view });
  vi.stubGlobal("fetch", fetcher);
  render(<AlertsSettings />);
  await unlock();
  expect(screen.getByRole("heading", { name: "Alerts" })).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("Total run capital · ₦"), {
    target: { value: "150000" },
  });
  fireEvent.change(screen.getByLabelText("Alert allowance"), {
    target: { value: "2" },
  });
  fireEvent.change(screen.getByLabelText("email delivery"), {
    target: { value: "off" },
  });
  fireEvent.change(screen.getByLabelText("next_win attempts"), {
    target: { value: "6" },
  });
  expect(fetcher).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole("button", { name: "Save alert settings" }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  expect(fetcher.mock.calls[1][0]).toBe("/api/v1/notifications/alert-settings");
  expect(JSON.parse(fetcher.mock.calls[1][1].body)).toMatchObject({
    capital: 150000,
    allowance: 2,
    email_enabled: false,
    slack_enabled: true,
    next_win_attempts: 6,
  });
  expect(await screen.findByText(/Saved. Both channels/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Lock settings" }));
  expect(screen.getByLabelText("Settings key")).toHaveValue("");
});
it("requires choosing an existing profile rather than silently replacing different capital", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({
      ok: true,
      json: async () => ({ ...view, needs_review: true }),
    });
  vi.stubGlobal("fetch", fetcher);
  render(<AlertsSettings />);
  await unlock();
  expect(
    screen.getByRole("button", { name: "Save alert settings" }),
  ).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "Use Slack · ₦100,000" }));
  expect(screen.getByLabelText("Total run capital · ₦")).toHaveValue(100000);
  fireEvent.click(screen.getByRole("button", { name: "Save alert settings" }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  expect(JSON.parse(fetcher.mock.calls[1][1].body).migration_source).toBe(
    "slack",
  );
});
it("shows production-only delivery restrictions and handles authentication failure", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce({ ok: false, status: 403 })
    .mockResolvedValue({
      ok: true,
      json: async () => ({ ...view, production_delivery_allowed: false }),
    });
  vi.stubGlobal("fetch", fetcher);
  render(<AlertsSettings />);
  fireEvent.change(screen.getByLabelText("Settings key"), {
    target: { value: "a".repeat(32) },
  });
  fireEvent.click(screen.getByRole("button", { name: "Unlock settings" }));
  expect(await screen.findByRole("status")).toHaveTextContent(
    "Settings key required",
  );
  await unlock();
  expect(
    screen.getByText(/Local and test environments cannot send alerts/),
  ).toBeInTheDocument();
});
