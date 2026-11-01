/** Monetary projections only. Evidence, odds, dates, counts and decisions are untouched. */
export function projectMoney<T>(value: T, factor: number): T {
  if (!Number.isFinite(factor) || factor <= 0)
    throw new Error("Invalid capital factor");
  function visit(item: unknown): unknown {
    if (Array.isArray(item)) return item.map(visit);
    if (item === null || typeof item !== "object") return item;
    return Object.fromEntries(
      Object.entries(item).map(([key, child]) => [
        key,
        key.endsWith("_minor") && typeof child === "number"
          ? child * factor
          : visit(child),
      ]),
    );
  }
  return visit(value) as T;
}
