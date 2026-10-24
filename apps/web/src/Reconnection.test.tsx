import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import App from "./App";
const payload = {
  schema_version: "1",
  mode: "foundation",
  currency: "NGN",
  display_timezone: "Africa/Lagos",
  competitions: [],
  strategies: [],
};
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
it("retains the last dashboard through failed refreshes and clears the banner after recovery", async () => {
  vi.useFakeTimers();
  const fetch = vi
    .fn()
    .mockResolvedValueOnce({ ok: true, json: async () => payload })
    .mockRejectedValueOnce(new Error("Network unavailable"))
    .mockResolvedValue({ ok: true, json: async () => payload });
  vi.stubGlobal("fetch", fetch);
  render(<App />);
  await act(async () => {});
  expect(screen.getByText("Bankroll not configured")).toBeInTheDocument();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(30000);
  });
  expect(screen.getByText("Reconnecting")).toBeInTheDocument();
  expect(screen.getByText(/last loaded/)).toHaveTextContent("WAT");
  expect(screen.getByText("Bankroll not configured")).toBeInTheDocument();
  expect(screen.queryByText("Connection interrupted")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Retry now" }));
  await act(async () => {});
  expect(screen.queryByText("Reconnecting")).not.toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(3);
  cleanup();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(60000);
  });
  expect(fetch).toHaveBeenCalledTimes(3);
});
