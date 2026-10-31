import { useEffect, useState } from "react";
import { getOverview, type Overview as OverviewData } from "./api";
import { Overview } from "./Overview";
import { SimulationHistory } from "./SimulationHistory";
import { StrategyList } from "./StrategyList";
import { Simulator } from "./Simulator";
import { LocalSyncControl } from "./LocalSyncControl";
import { Logo } from "./Logo";

const pages = ["Overview", "Strategies", "History", "Simulator"] as const;
type Page = (typeof pages)[number];

export default function App() {
  const [page, setPage] = useState<Page>("Overview");
  const [league, setLeague] = useState("all");
  const [data, setData] = useState<OverviewData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [updating, setUpdating] = useState(false);
  const [request, setRequest] = useState(0);
  const [lastSuccess, setLastSuccess] = useState<Date | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const refresh = async () => {
      try {
        const overview = await getOverview(controller.signal);
        if (!controller.signal.aborted) {
          const preparing = ["warming", "unavailable"].includes(
            overview.dashboard_cache?.status ?? "",
          );
          setUpdating(preparing);
          setData((previous) =>
            preparing &&
            previous &&
            !["warming", "unavailable"].includes(
              previous.dashboard_cache?.status ?? "",
            )
              ? previous
              : overview,
          );
          if (!preparing) setLastSuccess(new Date());
          setError(null);
        }
      } catch (cause: unknown) {
        if (!controller.signal.aborted) {
          setError(
            cause instanceof Error
              ? cause.message
              : "Unable to load the dashboard.",
          );
        }
      } finally {
        if (!controller.signal.aborted) timer = setTimeout(refresh, 30_000);
      }
    };
    void refresh();
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [request]);

  return (
    <div className="app-shell">
      <a href="#main" className="skip-link">
        Skip to content
      </a>
      <aside className="sidebar">
        <a className="brand" href="/" aria-label="KioTrad home">
          <Logo label="" />
          <span>KioTrad</span>
        </a>
        <nav aria-label="Main navigation">
          {pages.map((item, index) => (
            <button
              key={item}
              aria-current={page === item ? "page" : undefined}
              onClick={() => setPage(item)}
            >
              <span aria-hidden="true">{["◫", "◇", "◷", "◈"][index]}</span>
              {item}
            </button>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <span className="foundation-dot" />
          Foundation<span>Independent by design</span>
        </div>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div>
            <span className="breadcrumb">Workspace /</span> {page}
          </div>
          <div className="topbar-right">
            {data && (
              <label className="league-select">
                <span className="sr-only">League</span>
                <select
                  value={league}
                  onChange={(e) => setLeague(e.target.value)}
                >
                  <option value="all">All leagues</option>
                  {data.competitions.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {data?.local_sync && (
              <span className="mode-badge">
                Local copy · synced{" "}
                {new Date(data.local_sync.completed_at).toLocaleString(
                  "en-GB",
                  { timeZone: "Africa/Lagos" },
                )}{" "}
                WAT
              </span>
            )}
            <span className="mode-badge">
              {data?.research_simulation
                ? "Research simulation"
                : data?.bankroll?.status === "paper"
                  ? "Paper trading"
                  : "Research foundation"}
            </span>
          </div>
        </header>
        <main id="main" tabIndex={-1}>
          <LocalSyncControl onReady={() => setRequest((n) => n + 1)} />
          {updating &&
            data &&
            !["warming", "unavailable"].includes(
              data.dashboard_cache?.status ?? "",
            ) && (
              <div className="reconnection-banner" role="status">
                Updating dashboard · showing the last successful view
              </div>
            )}
          {error && data && (
            <div className="reconnection-banner" role="status">
              <span className="reconnection-dot" aria-hidden="true" />
              <div>
                <strong>Reconnecting</strong>
                <small>
                  Showing saved view · last loaded{" "}
                  {lastSuccess?.toLocaleTimeString("en-GB", {
                    timeZone: "Africa/Lagos",
                    hour: "2-digit",
                    minute: "2-digit",
                    second: "2-digit",
                  })}{" "}
                  WAT
                </small>
              </div>
              <button onClick={() => setRequest((n) => n + 1)}>
                Retry now
              </button>
            </div>
          )}
          {data?.dashboard_cache?.status === "stale" && (
            <div className="reconnection-banner" role="status">
              Showing saved dashboard · background refresh pending
              {data.dashboard_cache.generated_at
                ? ` · ${new Date(data.dashboard_cache.generated_at).toLocaleString("en-GB", { timeZone: "Africa/Lagos" })} WAT`
                : ""}
            </div>
          )}
          {error && !data ? (
            <section className="message-panel" role="alert">
              <h1>Connection interrupted</h1>
              <p>{error}</p>
              <button
                onClick={() => {
                  setError(null);
                  setRequest((n) => n + 1);
                }}
              >
                Retry connection
              </button>
            </section>
          ) : data?.dashboard_cache?.status === "warming" ||
            data?.dashboard_cache?.status === "unavailable" ? (
            <section className="message-panel" role="status">
              <h1>
                {data.dashboard_cache.status === "warming"
                  ? "Preparing dashboard"
                  : "Dashboard cache unavailable"}
              </h1>
              <p>
                The background worker is rebuilding the view. Stored source data
                is preserved; this page retries automatically.
              </p>
            </section>
          ) : !data ? (
            <p className="loading" role="status">
              Connecting to KioTrad…
            </p>
          ) : page === "Overview" ? (
            <Overview data={data} league={league} />
          ) : page === "Simulator" ? (
            <Simulator data={data} league={league} />
          ) : page === "Strategies" ? (
            <>
              <div className="page-intro">
                <p className="eyebrow">Hypotheses become measurable rules</p>
                <h1>The strategy library.</h1>
                <p>
                  Open a strategy to inspect its definition and observed
                  fixtures. Research simulations evaluate collected events and
                  saved odds, including data recovered after an interruption.
                </p>
              </div>
              <StrategyList
                strategies={data.strategies}
                competitions={data.competitions}
                monitoring={data.strategy_monitoring?.filter(
                  (row) => league === "all" || row.competition === league,
                )}
              />
            </>
          ) : (
            <SimulationHistory key={league} league={league} />
          )}
        </main>
        <footer>
          <span>KioTrad · Observe. Test. Track.</span>
          <span>NGN · Africa/Lagos display · UTC storage</span>
        </footer>
      </div>
    </div>
  );
}
