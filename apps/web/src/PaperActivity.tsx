import { naira, type Overview } from "./api";

export function PaperActivity({ data }: { data: Overview }) {
  const run = data.active_run;
  const attempt = run?.attempts[run.attempts.length - 1];
  if (!run) return null;
  return (
    <div className="paper-active">
      <p className="eyebrow">Shared paper bankroll · one run at a time</p>
      <h3>
        {run.team} · {run.competition.replace("virtual-", "")}
      </h3>
      <p>
        {run.strategy_id.replaceAll("-", " ")} · season {run.season_id}
      </p>
      <p>{run.status.replaceAll("_", " ")}</p>
      {attempt && (
        <p>
          Attempt {attempt.attempt}/4 · day {attempt.matchday} · stake{" "}
          {naira(attempt.stake_minor)} · recorded odds {attempt.odds}
        </p>
      )}
      {attempt && (
        <p>
          <time dateTime={attempt.scheduled_start_at}>
            Scheduled{" "}
            {new Intl.DateTimeFormat("en-NG", {
              timeZone: "Africa/Lagos",
              dateStyle: "medium",
              timeStyle: "medium",
              hourCycle: "h23",
            }).format(new Date(attempt.scheduled_start_at))}{" "}
            WAT
          </time>
        </p>
      )}
      {attempt?.status === "pending" && (
        <p>
          Potential gross payout {naira(attempt.potential_payout_minor)} ·
          result unconfirmed
        </p>
      )}
      <div className="attempts" aria-label="Paper attempt progression">
        {[1, 2, 3, 4].map((n) => (
          <span
            key={n}
            className={
              run.attempts[n - 1]?.status === "settled" ? "settled-attempt" : ""
            }
          >
            {n}
          </span>
        ))}
      </div>
    </div>
  );
}

export function PaperHistory({ data }: { data: Overview }) {
  const runs = data.recent_runs ?? [];
  return (
    <section className="paper-history" aria-label="Paper run history">
      <p className="eyebrow">Paper trading only</p>
      <h2>Recorded runs.</h2>
      {!runs.length ? (
        <p>No completed paper runs yet.</p>
      ) : (
        <div className="paper-table-scroll">
          <table>
            <thead>
              <tr>
                <th>League / season</th>
                <th>Strategy / team</th>
                <th>Status</th>
                <th>Staked</th>
                <th>Net</th>
              </tr>
            </thead>
            <tbody>
              {runs.map((run) => (
                <tr key={run.id}>
                  <td>
                    {run.competition.replace("virtual-", "")} / {run.season_id}
                  </td>
                  <td>
                    {run.strategy_id.replaceAll("-", " ")}
                    <br />
                    {run.team}
                  </td>
                  <td>{run.status.replaceAll("_", " ")}</td>
                  <td>
                    {naira(
                      run.attempts.reduce((sum, a) => sum + a.stake_minor, 0),
                    )}
                  </td>
                  <td>{naira(run.net_minor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
