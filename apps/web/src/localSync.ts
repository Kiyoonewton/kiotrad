export function localSyncEnabled(location: Pick<Location, "hostname" | "port"> = window.location) {
  return import.meta.env.VITE_LOCAL_SYNC === "true" &&
    ["localhost", "127.0.0.1"].includes(location.hostname) && location.port === "5174";
}

