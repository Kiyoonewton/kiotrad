import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { EmailAlertSettings } from "./EmailAlertSettings";
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const settings = {
  enabled: true,
  capital: 50000,
  lead_seconds: 10,
  league: "all",
  next_win_enabled: true,
  next_win_qualification: 5,
  next_win_attempts: 4,
  next_season_enabled: true,
  next_season_qualification: 5,
  next_season_attempts: 4,
};
it("loads and explicitly saves independent email settings with an in-memory settings key", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue({
      ok: true,
      json: async () => ({
        settings,
        delivery_enabled: false,
        smtp_configured: false,
      }),
    });
  vi.stubGlobal("fetch", fetcher);
  render(<EmailAlertSettings />);
  fireEvent.change(screen.getByLabelText("Settings key"), {
    target: { value: "a".repeat(32) },
  });
  fireEvent.click(screen.getByRole("button", { name: "Unlock settings" }));
  expect(await screen.findByLabelText("Total run capital · ₦")).toHaveValue(
    50000,
  );
  fireEvent.change(screen.getByLabelText("next_win attempts"), {
    target: { value: "6" },
  });
  fireEvent.change(screen.getByLabelText("next_season qualification"), {
    target: { value: "7" },
  });
  expect(fetcher).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole("button", { name: "Save email settings" }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  const call = fetcher.mock.calls[1][1];
  expect(call.method).toBe("PUT");
  expect(JSON.parse(call.body)).toMatchObject({
    capital: 50000,
    next_win_attempts: 6,
    next_season_qualification: 7,
    next_season_attempts: 4,
  });
  expect(call.headers.Authorization).toBe(`Bearer ${"a".repeat(32)}`);
  expect(
    await screen.findByText(/Saved. The next worker scan/),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Lock settings" }));
  expect(screen.getByLabelText("Settings key")).toHaveValue("");
});
it("shows an authorization error without displaying editable settings", async () => {
  vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 403 }));
  render(<EmailAlertSettings />);
  fireEvent.change(screen.getByLabelText("Settings key"), {
    target: { value: "x".repeat(32) },
  });
  fireEvent.click(screen.getByRole("button", { name: "Unlock settings" }));
  expect(await screen.findByRole("status")).toHaveTextContent(
    "Settings key required or not accepted",
  );
  expect(
    screen.queryByLabelText("Total run capital · ₦"),
  ).not.toBeInTheDocument();
});
