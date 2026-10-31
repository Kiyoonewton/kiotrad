export function localSyncEnabled(location: Pick<Location, "hostname" | "port"> = window.location) {
  return import.meta.env.VITE_LOCAL_SYNC === "true" &&
    ["localhost", "127.0.0.1"].includes(location.hostname) && location.port === "5174";
}


export function syncAge(timestamp: string | null | undefined, now: number): string {
  const completed = timestamp ? Date.parse(timestamp) : NaN;
  if (!Number.isFinite(completed)) return "not recorded";
  if (completed > now + 60_000) return "timestamp ahead of device clock";
  const minutes = Math.max(0, Math.floor((now - completed) / 60_000));
  if (minutes === 0) return "less than a minute ago";
  if (minutes < 60) return `${minutes}m ago`;
  if (minutes < 1440) return `${Math.floor(minutes / 60)}h ${minutes % 60}m ago`;
  return `${Math.floor(minutes / 1440)}d ${Math.floor((minutes % 1440) / 60)}h ago`;
}

