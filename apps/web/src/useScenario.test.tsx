import { act, cleanup, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { initialScenario, useScenario, validScenario } from "./useScenario";

const settings = {
  capital: 50000,
  qualification: 5,
  attempts: 4,
  allowance: 1,
  strategy: "all",
};
const response = (balance: number) => ({
  ok: true,
  status: 200,
  json: async () => ({ status: "available", balance_minor: balance }),
});
beforeEach(() => {
  vi.useFakeTimers();
  localStorage.clear();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it("debounces edits and includes every setting and league in the calculation request", async () => {
  const fetch = vi.fn().mockResolvedValue(response(5100000));
  vi.stubGlobal("fetch", fetch);
  const { result, rerender } = renderHook(
    ({ capital }) => useScenario({ ...settings, capital }, "virtual-euro"),
    { initialProps: { capital: 50000 } },
  );
  rerender({ capital: 60000 });
  rerender({ capital: 70000 });
  await act(async () => {
    await vi.advanceTimersByTimeAsync(401);
  });
  expect(fetch).toHaveBeenCalledTimes(1);
  const url = new URL(fetch.mock.calls[0][0], "http://localhost");
  expect(Object.fromEntries(url.searchParams)).toEqual({
    capital: "70000",
    qualification: "5",
    attempts: "4",
    allowance: "1",
    strategy: "all",
    league: "virtual-euro",
  });
  expect(result.current.view?.balance_minor).toBe(5100000);
});

it("does not present a late response for settings the user has already changed", async () => {
  let resolveOld!: (v: ReturnType<typeof response>) => void;
  const fetch = vi
    .fn()
    .mockReturnValueOnce(
      new Promise((resolve) => {
        resolveOld = resolve;
      }),
    )
    .mockResolvedValue(response(6200000));
  vi.stubGlobal("fetch", fetch);
  const { result, rerender } = renderHook(
    ({ qualification }) => useScenario({ ...settings, qualification }, "all"),
    { initialProps: { qualification: 5 } },
  );
  await act(async () => {
    await vi.advanceTimersByTimeAsync(401);
  });
  rerender({ qualification: 6 });
  expect(result.current.view).toBeNull();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(401);
  });
  await act(async () => {
    resolveOld(response(9999999));
  });
  expect(result.current.view?.balance_minor).toBe(6200000);
});

it("waits for prepared evidence without showing a fabricated empty financial result", async () => {
  const fetch = vi
    .fn()
    .mockResolvedValueOnce({
      ok: true,
      status: 202,
      json: async () => ({ status: "warming", message: "Preparing evidence" }),
    })
    .mockResolvedValue(response(5000000));
  vi.stubGlobal("fetch", fetch);
  const { result } = renderHook(() => useScenario(settings, "all"));
  await act(async () => {
    await vi.advanceTimersByTimeAsync(401);
  });
  expect(result.current.message).toBe("Preparing evidence");
  expect(result.current.view).toBeNull();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(3001);
  });
  expect(result.current.view?.balance_minor).toBe(5000000);
});

it("rejects unsupported settings before a request and restores browser preferences", async () => {
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  const { result } = renderHook(() =>
    useScenario({ ...settings, attempts: 11 }, "all"),
  );
  await act(async () => {
    await vi.advanceTimersByTimeAsync(1000);
  });
  expect(fetch).not.toHaveBeenCalled();
  expect(result.current.view).toBeNull();
  expect(validScenario({ ...settings, capital: 100.5 })).toBe(false);
  localStorage.setItem(
    "kiotrad:simulator:v1",
    JSON.stringify({ ...settings, allowance: 2 }),
  );
  expect(initialScenario(7000).allowance).toBe(2);
});
