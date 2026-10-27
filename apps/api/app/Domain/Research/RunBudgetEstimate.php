<?php

namespace App\Domain\Research;

use DomainException;

final class RunBudgetEstimate
{
    public const VERSION = 'historical-low-odds-configurable-2.1.0';

    public function calculate(array $odds, int $available, int $multiplier = 1): array
    {
        if (! in_array($multiplier, [1, 2], true) || $available < 0 || $available > 1000000000 || count($odds) < 1 || count($odds) > 10) {
            throw new DomainException('invalid_budget');
        }
        foreach ($odds as $price) {
            if (! is_int($price) || $price <= 10000 || $price > 10000000) {
                throw new DomainException('invalid_historical_odds');
            }
        }
        $low = 1;
        $high = intdiv($available, 100);
        $best = null;
        while ($low <= $high) {
            $target = intdiv($low + $high, 2);
            $plan = $this->project($odds, $target * 100, intdiv($available, $multiplier));
            if ($available >= $plan['total_minor'] * $multiplier) {
                $best = [...$plan, 'profit_per_attempt_minor' => $target * 100];
                $low = $target + 1;
            } else {
                $high = $target - 1;
            }
        }

        return ['policy_version' => self::VERSION, 'available_capital_minor' => $available,
            'status' => $best === null ? 'insufficient_allowance' : 'estimated', 'plan' => $best,
            'allowance_minor' => $best === null ? null : $best['total_minor'] * $multiplier,
            'allowance_multiplier' => $multiplier, 'basis' => 'lowest_observed_exact_venue_odds', 'guaranteed_maximum' => false];
    }

    private function project(array $odds, int $profit, int $budget): array
    {
        $losses = 0;
        $attempts = [];
        foreach ($odds as $index => $price) {
            $target = $losses + ($index + 1) * $profit;
            $denominator = ($price - 10000) * 100;
            $stake = max(10000, intdiv($target * 10000 + $denominator - 1, $denominator) * 100);
            $attempts[] = ['attempt' => $index + 1, 'odds_scaled' => $price, 'prior_losses_minor' => $losses,
                'stake_minor' => $stake, 'profit_after_recovery_minor' => ($index + 1) * $profit];
            $losses += $stake;
            if ($losses > $budget) {
                break;
            }
        }

        return ['total_minor' => $losses, 'attempts' => $attempts];
    }
}
