import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { LocalSyncControl, LocalSyncPanel } from "./LocalSyncControl";
import { localSyncEnabled } from "./localSync";

afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); vi.unstubAllEnvs(); });
const idle = { status: "idle", phase: "Ready to sync", job_id: null, token: "ephemeral", configured: true };
const response = (data: object, status = 200) => Promise.resolve({ ok: status < 400, status, json: async () => data });
async function settle() { await act(async () => { await Promise.resolve(); }); }

it("is unavailable in production builds and on nonlocal origins", () => {
  const fetcher = vi.fn();
  vi.stubGlobal("fetch", fetcher);
  vi.stubEnv("VITE_LOCAL_SYNC", "false");
  render(<LocalSyncControl onReady={vi.fn()} />);
  expect(screen.queryByRole("button", { name: "Sync prod → local" })).not.toBeInTheDocument();
  expect(fetcher).not.toHaveBeenCalled();
  vi.stubEnv("VITE_LOCAL_SYNC", "true");
  expect(localSyncEnabled({ hostname: "production.example", port: "5174" })).toBe(false);
  expect(localSyncEnabled({ hostname: "localhost", port: "5173" })).toBe(false);
  expect(localSyncEnabled({ hostname: "localhost", port: "5174" })).toBe(true);
});

it("posts no parameters with the helper token, disables duplicates and refreshes once ready", async () => {
  vi.useFakeTimers();
  const onReady = vi.fn();
  const running = { ...idle, status: "running", phase: "Downloading new evidence", job_id: "job-one", done: 2, total: 4, elapsed_seconds: 10 };
  const ready = { ...running, status: "ready", phase: "Dashboard ready to view" };
  const fetcher = vi.fn().mockImplementationOnce(() => response(idle))
    .mockImplementationOnce(() => response(running, 202)).mockImplementation(() => response(ready));
  vi.stubGlobal("fetch", fetcher);
  render(<LocalSyncPanel onReady={onReady} />);
  await settle();
  fireEvent.click(screen.getByRole("button", { name: "Sync prod → local" }));
  await settle();
  expect(fetcher.mock.calls[1][1]).toMatchObject({ method: "POST", headers: { "X-Kiotrad-Sync": "ephemeral" } });
  expect(fetcher.mock.calls[1][1]).not.toHaveProperty("body");
  expect(screen.getByRole("button", { name: "Syncing…" })).toBeDisabled();
  expect(screen.getByRole("status")).toHaveTextContent("50%");
  await act(async () => { await vi.advanceTimersByTimeAsync(2000); });
  expect(onReady).toHaveBeenCalledTimes(1);
  expect(screen.getByText(/Dashboard ready to view. Production was not modified./)).toBeInTheDocument();
  await act(async () => { await vi.advanceTimersByTimeAsync(2000); });
  expect(onReady).toHaveBeenCalledTimes(1);
});

it("shows one-time setup when the host helper is offline", async () => {
  vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));
  render(<LocalSyncPanel onReady={vi.fn()} />);
  await settle();
  expect(screen.getByRole("button", { name: "Sync prod → local" })).toBeDisabled();
  expect(screen.getByText("python3 engine/local/sync_agent.py --setup")).toBeInTheDocument();
});

it("keeps the last successful snapshot age when the helper reports a failed attempt", async () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date("2026-10-31T18:30:00Z"));
  vi.stubGlobal("fetch", vi.fn().mockImplementation(() => response({ ...idle, status: "failed", phase: "Sync failed", job_id: "failed-job" })));
  render(<LocalSyncPanel onReady={vi.fn()} lastSyncAt="2026-10-31T18:00:00Z" />);
  await settle();
  expect(screen.getByTitle("31/10/2026, 19:00:00 WAT")).toHaveTextContent("30m ago");
  expect(screen.getByRole("status")).toHaveTextContent("Sync failed");
});
