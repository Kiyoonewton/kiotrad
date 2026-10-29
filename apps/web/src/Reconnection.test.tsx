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

it("shows a cold snapshot as preparing rather than empty financial results", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        ...payload,
        dashboard_cache: { status: "warming", generated_at: null },
      }),
    }),
  );
  render(<App />);
  expect(await screen.findByText("Preparing dashboard")).toBeInTheDocument();
  expect(screen.queryByText("Bankroll not configured")).not.toBeInTheDocument();
});

it("marks an old server snapshot as stale even when the HTTP request succeeds", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        ...payload,
        dashboard_cache: {
          status: "stale",
          generated_at: "2026-10-24T10:00:00Z",
        },
      }),
    }),
  );
  render(<App />);
  expect(await screen.findByText(/Showing saved dashboard/)).toHaveTextContent(
    "11:00:00",
  );
  expect(screen.getByText("Bankroll not configured")).toBeInTheDocument();
});

it.each(["warming", "unavailable"])(
  "retains the displayed dashboard when polling returns %s",
  async (status) => {
    vi.useFakeTimers();
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValueOnce({ ok: true, json: async () => payload })
        .mockResolvedValueOnce({
          ok: true,
          json: async () => ({ ...payload, dashboard_cache: { status } }),
        })
        .mockResolvedValue({ ok: true, json: async () => payload }),
    );
    render(<App />);
    await act(async () => {});
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30000);
    });
    expect(screen.getByText(/Updating dashboard/)).toBeInTheDocument();
    expect(screen.getByText("Bankroll not configured")).toBeInTheDocument();
    expect(screen.queryByText("Preparing dashboard")).not.toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30000);
    });
    expect(screen.queryByText(/Updating dashboard/)).not.toBeInTheDocument();
  },
);
