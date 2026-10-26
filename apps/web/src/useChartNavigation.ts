import { useRef, useState, type PointerEvent } from "react";

export function useChartNavigation(
  total: number,
  limit: number,
  setLimit: (n: number) => void,
) {
  const [offset, setOffset] = useState(0);
  const drag = useRef<{ x: number; offset: number } | null>(null);
  const count = Math.min(total, limit);
  const maxOffset = Math.max(0, total - count);
  const bounded = Math.min(offset, maxOffset);
  const start = Math.max(0, total - count - bounded);
  return {
    start,
    count,
    maxOffset,
    pan: (value: number) =>
      setOffset(Math.max(0, Math.min(maxOffset, total - count - value))),
    zoom: (inside: boolean) =>
      setLimit(
        Math.max(
          1,
          Math.min(
            Math.max(1, total),
            Math.round(count * (inside ? 0.7 : 1.5)) + (inside ? 0 : 1),
          ),
        ),
      ),
    fit: () => {
      setOffset(0);
      setLimit(Math.max(1, total));
    },
    down: (e: PointerEvent<SVGSVGElement>) => {
      if (e.button !== 0) return;
      drag.current = { x: e.clientX, offset: bounded };
      e.currentTarget.setPointerCapture(e.pointerId);
    },
    move: (e: PointerEvent<SVGSVGElement>) => {
      if (!drag.current) return;
      const width = Math.max(1, e.currentTarget.getBoundingClientRect().width);
      setOffset(
        Math.max(
          0,
          Math.min(
            maxOffset,
            drag.current.offset +
              Math.round(((e.clientX - drag.current.x) / width) * count),
          ),
        ),
      );
    },
    up: () => {
      drag.current = null;
    },
  };
}
