import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { LocalSyncAge } from "./LocalSyncAge";
import { syncAge } from "./localSync";

afterEach(() => { cleanup(); vi.useRealTimers(); });

it("formats elapsed sync time across minute, hour and day boundaries", () => {
  const at = "2026-10-31T18:00:00Z";
  const started = Date.parse(at);
  expect(syncAge(at, started + 10_000)).toBe("less than a minute ago");
  expect(syncAge(at, started + 12 * 60_000)).toBe("12m ago");
  expect(syncAge(at, started + 135 * 60_000)).toBe("2h 15m ago");
  expect(syncAge(at, started + 26 * 60 * 60_000)).toBe("1d 2h ago");
  expect(syncAge(null, started)).toBe("not recorded");
  expect(syncAge("invalid", started)).toBe("not recorded");
  expect(syncAge(at, started - 120_000)).toBe("timestamp ahead of device clock");
});

it("updates elapsed time and exposes the exact Lagos timestamp", async () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date("2026-10-31T18:01:40Z"));
  render(<LocalSyncAge completedAt="2026-10-31T18:00:00Z" />);
  const time = screen.getByTitle("31/10/2026, 19:00:00 WAT");
  expect(time).toHaveTextContent("1m ago");
  expect(time).toHaveAttribute("datetime", "2026-10-31T18:00:00Z");
  await act(async () => { await vi.advanceTimersByTimeAsync(30_000); });
  expect(time).toHaveTextContent("2m ago");
});

it("does not invent a sync timestamp before the first successful snapshot", () => {
  render(<LocalSyncAge />);
  expect(screen.getByText("Last production sync: not recorded")).toBeInTheDocument();
});
