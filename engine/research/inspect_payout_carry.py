#!/usr/bin/env python3
"""Read-only comparison of two cached retrospective scenario results; no source or settings writes."""
import argparse
import json
from decimal import Decimal
from urllib.parse import urlencode
from urllib.request import urlopen


def money(value):
    return "—" if value is None else f"NGN {Decimal(value) / 100:,.2f}"


def table(headers, rows):
    rows = [[str(cell) for cell in row] for row in rows]
    widths = [max([len(header)] + [len(row[i]) for row in rows]) for i, header in enumerate(headers)]
    print(" | ".join(header.ljust(widths[i]) for i, header in enumerate(headers)))
    print("-+-".join("-" * width for width in widths))
    for row in rows:
        print(" | ".join(cell.ljust(widths[i]) for i, cell in enumerate(row)))


def audit_plan(plan, profit):
    previous = spent = 0
    for row in plan["attempts"]:
        denominator = (row["odds_scaled"] - 10000) * 100
        stake = max(10000, ((previous + profit) * 10000 + denominator - 1) // denominator * 100)
        payout = stake * row["actual_odds_scaled"] // 10000
        spent += stake
        if (row["stake_minor"], row["potential_payout_minor"], row["previous_potential_payout_minor"],
            row["total_staked_minor"], row["net_if_won_minor"]) != (stake, payout, previous, spent, payout - spent):
            raise ValueError("Plan arithmetic mismatch; stop and inspect the response.")
        previous = payout
    if spent != plan["total_minor"]:
        raise ValueError("Plan total mismatch.")


def plan_table(plan, profit):
    audit_plan(plan, profit)
    table(["Attempt", "Day", "Est odds", "Actual odds", "Calculation", "Stake", "Potential payout", "Spent", "Net if won"], [
        [r["attempt"], r["matchday"], f'{r["odds_scaled"] / 10000:.2f}', f'{r["actual_odds_scaled"] / 10000:.2f}',
         f'({money(r["previous_potential_payout_minor"])} + {money(profit)}) / {(Decimal(r["odds_scaled"]) - 10000) / 10000}',
         money(r["stake_minor"]), money(r["potential_payout_minor"]), money(r["total_staked_minor"]), money(r["net_if_won_minor"])]
        for r in plan["attempts"]])


def inspect(view, run_id):
    run = next((r for r in view["runs"] if r["id"] == run_id), None)
    print(f'\nALLOWANCE x{view["settings"]["allowance"]} | {run_id}')
    if not run:
        print("Not present in this result; may be excluded or beyond the global profit stop.")
        return False
    print(f'Team: {run.get("team", "unknown")} | status: {run["status"]}')
    if (not run.get("estimate", {}).get("baseline") or run["status"].startswith("skipped_")
            or "capital_at_entry_minor" not in run):
        print(json.dumps({key: run[key] for key in ["status", "blocked_by", "skip_reason", "budget_diagnostic"] if key in run}, indent=2))
        return False
    estimate = run["estimate"]
    plan, baseline = estimate["plan"], estimate["baseline"]
    target = plan["profit_per_attempt_minor"]
    print("\nNGN 100 BASELINE (full-window hypothetical losses)")
    plan_table(baseline, 10000)
    print(f'\nTarget = NGN 100 x {money(estimate["scaling_numerator_minor"])} / {money(estimate["scaling_denominator_minor"])}')
    print(f'Selected target: {money(target)} | rounding adjustment: {estimate["rounding_adjusted"]}')
    print("\nCAPITAL-SIZED PLAN (potential payouts, not booked results)")
    plan_table(plan, target)
    if plan["total_minor"] * estimate["allowance_multiplier"] != estimate["allowance_minor"]:
        raise ValueError("Allowance mismatch.")
    if estimate["allowance_minor"] > run["capital_at_entry_minor"]:
        raise ValueError("Accepted plan exceeds entry balance.")
    print("\nACTUALLY SIMULATED ATTEMPTS (stops at first win)")
    attempts = run.get("attempts", [])
    table(["Day", "Fixture", "Stake", "Booked payout", "Match ID", "Price capture"], [
        [a.get("matchday"), f'{a.get("home_team", "?")} vs {a.get("away_team", "?")}', money(a["stake_minor"]),
         money(a.get("payout_minor")), a.get("provider_match_id", "unknown"), a.get("price_capture_id", "unknown")]
        for a in attempts])
    if sum(a["stake_minor"] for a in attempts) != run.get("total_staked_minor", 0):
        raise ValueError("Placed stake total mismatch.")
    if run.get("net_minor") is not None and run["gross_payout_minor"] - run["total_staked_minor"] != run["net_minor"]:
        raise ValueError("Settlement net mismatch.")
    table(["Entry balance", "Sizing capital", "Reserved allowance", "Staked", "Payout", "Net"], [[
        money(run.get("capital_at_entry_minor")), money(run.get("sizing_capital_at_entry_minor")),
        money(estimate["allowance_minor"]), money(run.get("total_staked_minor")),
        money(run.get("gross_payout_minor")), money(run.get("net_minor"))]])
    print("\nSOURCE FIXTURES (scheduled kickoff UTC; quote capture is separate)")
    table(["Day", "Kickoff UTC", "Historical minimum season", "Minimum source match", "Actual price verified pre-match"], [
        [f.get("matchday"), f.get("scheduled_start_at"), f.get("historical_minimum", {}).get("season_id", "unknown"),
         f.get("historical_minimum", {}).get("provider_match_id", "unknown"), f.get("price_timing_verified", False)]
        for f in run.get("fixtures", [])])
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="http://localhost:5174")
    parser.add_argument("--capital", type=int, default=30000)
    parser.add_argument("--mode", choices=["fixed", "compound"], default="fixed")
    parser.add_argument("--profit-stop", type=int, default=0)
    parser.add_argument("--next-win-qualification", type=int, default=5)
    parser.add_argument("--next-win-attempts", type=int, default=4)
    parser.add_argument("--next-season-qualification", type=int, default=5)
    parser.add_argument("--next-season-attempts", type=int, default=4)
    parser.add_argument("--run-id")
    args = parser.parse_args()
    settings = dict(capital=args.capital, capital_mode=args.mode, profit_stop=args.profit_stop,
                    qualification=5, attempts=4, strategy="all", league="all", staking_model="payout_carry_v1",
                    next_win_qualification=args.next_win_qualification, next_win_attempts=args.next_win_attempts,
                    next_season_qualification=args.next_season_qualification, next_season_attempts=args.next_season_attempts)
    views = []
    for allowance in [1, 2]:
        print(f"Reading scenario x{allowance}...", flush=True)
        with urlopen(args.url.rstrip("/") + "/api/v1/research/scenario?" + urlencode(dict(settings, allowance=allowance)), timeout=180) as response:
            view = json.load(response)
        if view.get("status") != "available" or not view.get("retrospective_odds_sizing"):
            raise ValueError("New model not ready. Update services or wait for reusable evidence preparation.")
        views.append(view)
    if (views[0]["data_revision"], views[0]["source_as_of"]) != (views[1]["data_revision"], views[1]["source_as_of"]):
        raise ValueError("Source revision changed between requests. Retry for a comparable snapshot.")
    available = [{r["id"] for r in v["runs"] if r.get("estimate", {}).get("baseline") and not r["status"].startswith("skipped_") and r.get("funded") is not False} for v in views]
    common = available[0] & available[1]
    selected = args.run_id or next((r["id"] for r in views[0]["runs"] if r["id"] in common and len(r.get("attempts", [])) >= 2), None)
    selected = selected or next((r["id"] for r in views[0]["runs"] if r["id"] in common), None)
    if not selected:
        raise ValueError("No common planned run found for both allowances. Review source availability or choose a run ID.")
    print("\nRETROSPECTIVE RESEARCH: full-window recorded actual odds inform initial sizing; not executable-at-entry proof.")
    print("Settings:", json.dumps(settings, sort_keys=True))
    print("Evidence revision:", views[0]["data_revision"], "| as of:", views[0]["source_as_of"])
    inspected = [inspect(view, selected) for view in views]
    print("\nPlan arithmetic: PASS." if any(inspected) else "\nNo funded plan to audit.")
    print("No evidence, bankroll settings or paper-ledger writes.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        raise SystemExit(str(error) if type(error) is ValueError else f"Inspection stopped ({type(error).__name__}). Check services, model deployment, parameters and evidence readiness; no credentials printed.") from None
