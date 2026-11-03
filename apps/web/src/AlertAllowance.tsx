export function AlertAllowance({
  value,
  change,
}: {
  value: number;
  change: (value: number) => void;
}) {
  return (
    <label className="alert-allowance">
      Allowance{" "}
      <select
        aria-label="Alert allowance"
        value={value}
        onChange={(event) => change(Number(event.target.value))}
      >
        <option value={1}>×1</option>
        <option value={2}>×2</option>
      </select>
    </label>
  );
}
