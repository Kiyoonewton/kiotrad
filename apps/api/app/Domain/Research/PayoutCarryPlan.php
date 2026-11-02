<?php

namespace App\Domain\Research;

use DomainException;

/** Retrospective: the complete selected window's recorded actual odds size the initial target. */
final class PayoutCarryPlan
{
    public const VERSION = 'payout-carry-retrospective-1.0.0';

    public function calculate(array $fixtures, int $capital, int $multiplier = 1): array
    {
        if ($capital < 1 || $capital > 1000000000 || ! in_array($multiplier, [1, 2], true) || count($fixtures) < 1 || count($fixtures) > 10) {
            throw new DomainException('invalid_carry_plan');
        }
        foreach ($fixtures as $fixture) {
            foreach (['historical_odds_scaled', 'actual_odds_scaled'] as $name) {
                $price = $fixture[$name] ?? null;
                if (! is_int($price) || $price <= 10000 || $price > 10000000) {
                    return ['status' => 'missing_plan_odds', 'plan' => null, 'allowance_minor' => null,
                        'unresolved_matchday' => $fixture['matchday'] ?? null, 'missing_field' => $name];
                }
            }
        }
        $budget = intdiv($capital, $multiplier);
        // Baseline is ₦100 target, with whole-naira stakes and actual potential payouts.
        $baseline = $this->project($fixtures, 10000, 10000000000);
        if (! $baseline['complete']) {
            return ['status' => 'calculation_range_limit', 'plan' => null, 'allowance_minor' => null];
        }
        $ceiling = intdiv(10000 * $capital, $multiplier * $baseline['total_minor']);
        $plan = $this->project($fixtures, $ceiling, $budget);
        // Re-round the recurrence, not just its displayed totals. Reduce the target if needed.
        if (! $plan['complete']) {
            $low = 1;
            $high = $ceiling;
            $ceiling = 0;
            while ($low <= $high) {
                $target = intdiv($low + $high, 2);
                $trial = $this->project($fixtures, $target, $budget);
                if ($trial['complete']) {
                    $ceiling = $target;
                    $low = $target + 1;
                } else {
                    $high = $target - 1;
                }
            }
            $plan = $this->project($fixtures, $ceiling, $budget);
        }

        return ['policy_version' => self::VERSION, 'basis' => 'full_window_recorded_actual_odds_retrospective',
            'executable_at_entry' => false, 'available_capital_minor' => $capital,
            'status' => $ceiling > 0 && $plan['complete'] ? 'estimated' : 'insufficient_allowance',
            'baseline_profit_minor' => 10000, 'baseline' => $baseline,
            'scaling_numerator_minor' => $capital, 'scaling_denominator_minor' => $multiplier * $baseline['total_minor'],
            'allowance_multiplier' => $multiplier, 'spending_budget_minor' => $budget,
            'rounding_adjusted' => $ceiling !== intdiv(10000 * $capital, $multiplier * $baseline['total_minor']),
            'plan' => [...$plan, 'profit_per_attempt_minor' => $ceiling],
            'allowance_minor' => $plan['complete'] ? $plan['total_minor'] * $multiplier : null];
    }

    private function project(array $fixtures, int $profit, int $budget): array
    {
        $spent = $previous = 0;
        $rows = [];
        foreach ($fixtures as $index => $fixture) {
            $divisor = ($fixture['historical_odds_scaled'] - 10000) * 100;
            $stake = max(10000, intdiv(($previous + $profit) * 10000 + $divisor - 1, $divisor) * 100);
            if ($stake > $budget - $spent) {
                return ['total_minor' => $spent, 'attempts' => $rows, 'complete' => false];
            }
            $spent += $stake;
            $payout = intdiv($stake * $fixture['actual_odds_scaled'], 10000);
            $rows[] = ['attempt' => $index + 1, 'matchday' => $fixture['matchday'] ?? null,
                'odds_scaled' => $fixture['historical_odds_scaled'], 'actual_odds_scaled' => $fixture['actual_odds_scaled'],
                'previous_potential_payout_minor' => $previous, 'stake_minor' => $stake,
                'potential_payout_minor' => $payout, 'total_staked_minor' => $spent, 'net_if_won_minor' => $payout - $spent];
            $previous = $payout;
        }

        return ['total_minor' => $spent, 'attempts' => $rows, 'complete' => true];
    }
}
