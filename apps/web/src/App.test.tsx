import { afterEach, expect, it, vi } from "vitest";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from "@testing-library/react";
import App from "./App";

const payload = {
  schema_version: "1",
  mode: "foundation",
  currency: "NGN",
  display_timezone: "Africa/Lagos",
  competitions: [
    { id: "virtual-football", name: "English", feed_status: "not_connected" },
    {
      id: "virtual-bundesliga",
      name: "Bundesliga",
      feed_status: "not_connected",
    },
    { id: "virtual-euro", name: "Euro", feed_status: "not_connected" },
  ],
  strategies: [
    {
      id: "champion-next-win",
      name: "Champion — Next Win",
      version: "0.1.0-draft",
      status: "research",
      monitoring_enabled: false,
      bankroll_enabled: false,
      description: "Follow the previous champion after a delayed first win.",
      entry: "After settlement.",
      rules: {
        max_attempts: 4,
        minimum_stake_minor: 10000,
        profit_per_attempt_minor: 10000,
      },
      unresolved: ["Official tiebreaks."],
    },
  ],
};

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

it("shows unavailable feeds and no invented balance, supports league filtering and strategy inspection", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({ ok: true, json: async () => payload }),
  );
  render(<App />);
  expect(
    await screen.findByText("Bankroll not configured"),
  ).toBeInTheDocument();
  const leagues = screen.getByRole("region", {
    name: "League collection status",
  });
  expect(within(leagues).getAllByText("Not connected")).toHaveLength(3);
  fireEvent.change(screen.getByRole("combobox", { name: "League" }), {
    target: { value: "virtual-euro" },
  });
  expect(within(leagues).getByText("Euro")).toBeInTheDocument();
  expect(within(leagues).queryByText("English")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: /Strategies/ }));
  expect(
    screen.getByRole("heading", { name: "The strategy library." }),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByText("Champion — Next Win"));
  expect(screen.getByText("Official tiebreaks.")).toBeVisible();
});

it("reports API failure and can retry successfully", async () => {
  vi.stubGlobal(
    "fetch",
    vi
      .fn()
      .mockResolvedValueOnce({ ok: false })
      .mockResolvedValueOnce({ ok: true, json: async () => payload }),
  );
  render(<App />);
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "The API is unavailable",
  );
  fireEvent.click(screen.getByRole("button", { name: "Retry connection" }));
  expect(
    await screen.findByText("Bankroll not configured"),
  ).toBeInTheDocument();
});

it("shows captured season and truthful freshness without claiming live results", async () => {
  vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ ...payload, competitions: [{
      id: "virtual-football", name: "English", feed_status: "stale",
      season_id: "200", matchday: 4, last_success_at: "2026-10-24T00:00:00Z",
      fixture_count: 240, settled_count: 232,
      market_observation: "availability_unknown", unresolved_seasons: ["199"],
      latest_champion: { team_name: "Example team", season_id: "199", confirmed_at: "2026-10-23T22:00:00Z" },
    }] }),
  }));
  render(<App />);
  expect(await screen.findByText("Stale capture")).toBeInTheDocument();
  expect(screen.getByText("Season 200 · Odds day 4")).toBeInTheDocument();
  expect(screen.getByText(/Observed .* WAT/)).toBeInTheDocument();
  expect(screen.queryByText("Live now")).not.toBeInTheDocument();
  expect(screen.getByText("Availability unknown")).toBeInTheDocument();
  expect(screen.getByText("232/240 results recorded")).toBeInTheDocument();
  expect(screen.getByText("1 unresolved season(s)")).toBeInTheDocument();
  expect(screen.getByText("Verified champion: Example team (season 199)")).toBeInTheDocument();
  expect(screen.getByText(/Champion confirmed .*23:00:00 WAT/)).toBeInTheDocument();
});
