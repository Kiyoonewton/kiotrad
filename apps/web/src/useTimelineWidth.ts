import { useLayoutEffect, useState, type RefObject } from "react";

export function useTimelineWidth(
  ref: RefObject<HTMLDivElement | null>,
  count: number,
) {
  const [width, setWidth] = useState(1100);
  useLayoutEffect(() => {
    const element = ref.current;
    if (!element) return;
    const measure = () => {
      if (element.clientWidth > 0) setWidth(element.clientWidth);
    };
    measure();
    if (typeof ResizeObserver === "undefined") {
      window.addEventListener("resize", measure);
      return () => window.removeEventListener("resize", measure);
    }
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    return () => observer.disconnect();
  }, [ref, count]);
  return width;
}
