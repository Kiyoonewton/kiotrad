import { useEffect, useState } from "react";
import { getOverview, type Overview as OverviewData } from "./api";
import { Overview } from "./Overview";
import { StrategyList } from "./StrategyList";

const pages = ["Overview", "Strategies", "History"] as const;
type Page = (typeof pages)[number];

export default function App() {
  const [page, setPage] = useState<Page>("Overview");
  const [league, setLeague] = useState("all");
  const [data, setData] = useState<OverviewData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [request, setRequest] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const refresh = async () => {
      try {
        const overview = await getOverview(controller.signal);
        if (!controller.signal.aborted) {
          setData(overview);
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
          <svg width="36" height="36" viewBox="0 0 36 36" aria-hidden="true">
            <path
              d="M5 5h7v12L25 5h8L18 19l15 12H22L12 22v9H5z"
              fill="currentColor"
            />
          </svg>
          <span>KioTrad</span>
        </a>
        <nav aria-label="Main navigation">
          {pages.map((item, index) => (
            <button
              key={item}
              aria-current={page === item ? "page" : undefined}
              onClick={() => setPage(item)}
            >
              <span aria-hidden="true">{["◫", "◇", "◷"][index]}</span>
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
            <span className="mode-badge">Research foundation</span>
          </div>
        </header>
        <main id="main" tabIndex={-1}>
          {error ? (
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
          ) : !data ? (
            <p className="loading" role="status">
              Connecting to KioTrad…
            </p>
          ) : page === "Overview" ? (
            <Overview data={data} league={league} />
          ) : page === "Strategies" ? (
            <>
              <div className="page-intro">
                <p className="eyebrow">Hypotheses become measurable rules</p>
                <h1>The strategy library.</h1>
                <p>
                  Open a strategy to inspect its definition and observed
                  fixtures. Paper trading remains disabled.
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
            <section className="page-intro">
              <p className="eyebrow">An auditable record</p>
              <h1>Every run has a story.</h1>
              <p>
                No trading runs have been recorded. Settled attempts, skipped
                clashes and bankroll transactions will appear here.
              </p>
            </section>
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
