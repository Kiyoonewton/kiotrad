export function RunMapLegend({ shared }: { shared: boolean }) {
  if (!shared)
    return (
      <p className="subtle timeline-legend">
        Green profit · red loss · single-attempt runs are ticks. Scheduled spans
        do not establish settlement-time overlap.
      </p>
    );
  return (
    <div className="timeline-legend">
      <div className="run-map-legend" aria-label="Run map colour legend">
        {[
          ["#81c9a0", "Selected · profit"],
          ["#e88f86", "Selected · loss"],
          ["#62aee8", "Skipped · win observed"],
          ["#d8ab62", "Skipped · no win in window"],
          ["#8c99a2", "Unresolved"],
        ].map(([color, label]) => (
          <span key={label}>
            <i style={{ background: color }} />
            {label}
          </span>
        ))}
      </div>
      <p className="subtle">
        Skipped outcomes add no stakes or profit. Endpoints use recorded result
        confirmation where available; unresolved spans end at their last
        scheduled attempt. Late archive evidence can overstate overlap.
      </p>
    </div>
  );
}
