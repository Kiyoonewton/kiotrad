import { naira } from "./api";

export function ChartValueOverlay({
  value,
  y,
  label,
}: {
  value: number;
  y: number;
  label: string;
}) {
  return (
    <g pointerEvents="none" aria-label="Selected chart balance">
      <line
        x1="90"
        x2="860"
        y1={y}
        y2={y}
        stroke="#d6e3db"
        strokeDasharray="4 4"
      />
      <rect x="92" y="5" width="760" height="24" rx="4" fill="#15251e" />
      <text x="100" y="22" fill="#e4f1e9">
        {label}
      </text>
      <title>{naira(value)}</title>
    </g>
  );
}
