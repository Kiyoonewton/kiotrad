import { useEffect, useState } from "react";
import { syncAge } from "./localSync";

export function LocalSyncAge({ completedAt }: { completedAt?: string | null }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(timer);
  }, []);
  const valid = completedAt && Number.isFinite(Date.parse(completedAt));
  const exact = valid ? `${new Date(completedAt).toLocaleString("en-GB", { timeZone: "Africa/Lagos" })} WAT` : undefined;
  return <p className="local-sync-age">
    Last production sync: {valid
      ? <time dateTime={completedAt} title={exact} aria-label={exact}>{syncAge(completedAt, now)}</time>
      : "not recorded"}
  </p>;
}
