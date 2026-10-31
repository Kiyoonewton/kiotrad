import { useEffect, useRef, useState } from "react";
import "./local-sync.css";
import { localSyncEnabled } from "./localSync";
import { LocalSyncAge } from "./LocalSyncAge";

type SyncState = {
  status: "idle" | "running" | "preparing" | "ready" | "failed";
  phase: string;
  job_id: string | null;
  token?: string;
  configured?: boolean;
  elapsed_seconds?: number;
  done?: number | null;
  total?: number | null;
  error?: string;
};
const helper = "http://127.0.0.1:5176";

type SyncProps = { onReady: () => void; lastSyncAt?: string | null };

export function LocalSyncControl({ onReady, lastSyncAt }: SyncProps) {
  return localSyncEnabled() ? <LocalSyncPanel onReady={onReady} lastSyncAt={lastSyncAt} /> : null;
}

export function LocalSyncPanel({ onReady, lastSyncAt }: SyncProps) {
  const [state, setState] = useState<SyncState | null>(null);
  const [offline, setOffline] = useState(false);
  const [starting, setStarting] = useState(false);
  const [requestError, setRequestError] = useState("");
  const notified = useRef<string | null>(null);
  const readyCallback = useRef(onReady);
  useEffect(() => { readyCallback.current = onReady; }, [onReady]);
  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const poll = async () => {
      try {
        const response = await fetch(`${helper}/status`, { signal: controller.signal, credentials: "omit" });
        if (!response.ok) throw new Error();
        const data: SyncState = await response.json();
        if (controller.signal.aborted) return;
        setState(data);
        setOffline(false);
        if (data.status === "ready" && data.job_id && notified.current !== data.job_id) {
          notified.current = data.job_id;
          readyCallback.current();
        }
      } catch {
        if (!controller.signal.aborted) setOffline(true);
      } finally {
        if (!controller.signal.aborted) timer = setTimeout(poll, 2000);
      }
    };
    void poll();
    return () => { controller.abort(); clearTimeout(timer); };
  }, []);
  const busy = starting || ["running", "preparing"].includes(state?.status ?? "");
  const start = async () => {
    setStarting(true);
    setRequestError("");
    try {
      const response = await fetch(`${helper}/sync`, { method: "POST", credentials: "omit",
        headers: { "X-Kiotrad-Sync": state?.token ?? "" } });
      if (!response.ok && response.status !== 409) throw new Error();
      const data: SyncState = await response.json();
      setState((previous) => ({ ...previous, ...data }));
    } catch {
      setRequestError("Could not start sync. Check the local helper and retry.");
    } finally { setStarting(false); }
  };
  const measured = state?.total != null && state.total > 0 && state.done != null && state.done <= state.total;
  return <section className="local-sync-panel" aria-label="Local data sync">
    <div>
      <strong>Production → local data</strong>
      <LocalSyncAge completedAt={lastSyncAt} />
      <p role="status">{offline ? "Local sync helper is offline." : state?.phase ?? "Connecting to local sync helper…"}
        {state?.job_id && <> · {state.elapsed_seconds ?? 0}s elapsed</>}
        {measured && <> · {state!.done}/{state!.total} ({Math.floor(100 * state!.done! / state!.total!)}%)</>}
      </p>
      {measured && <progress aria-label="Current sync phase progress" value={state!.done!} max={state!.total!} />}
      {(offline || state?.configured === false) && <p>One-time Terminal setup: <code>python3 engine/local/sync_agent.py --setup</code></p>}
      {(requestError || state?.error) && <p role="alert">{requestError || state?.error}</p>}
      {state?.status === "ready" && !offline && <p>Dashboard ready to view. Production was not modified.</p>}
    </div>
    <button disabled={busy || offline || !state?.configured} onClick={() => void start()}>
      {busy ? "Syncing…" : "Sync prod → local"}
    </button>
  </section>;
}
