<?php

namespace App\Domain\Alerts;

use DomainException;

final class EntryStakePlan
{
    public const VERSION = 'entry-frozen-estimated-carry-1.0.0';

    public function calculate(array $fixtures, int $capital, int $allowance = 1): array
    {
        if ($capital < 10000 || $capital > 1000000000 || ! in_array($allowance, [1, 2], true) || count($fixtures) < 1 || count($fixtures) > 10) {
            throw new DomainException('invalid_entry_plan');
        }
        foreach ($fixtures as $i => $fixture) {
            $estimate = $fixture['estimated_odds_scaled'] ?? null;
            if (! is_int($estimate) || $estimate <= 10000 || $estimate > 10000000) {
                return ['status' => 'missing_historical_odds'];
            }
            if ($i === 0 && (! is_int($fixture['current_odds_scaled'] ?? null) || $fixture['current_odds_scaled'] <= 10000 || $fixture['current_odds_scaled'] > 10000000)) {
                return ['status' => 'missing_verified_current_price'];
            }
            if ($i === 0 && $fixture['current_odds_scaled'] < $estimate) {
                return ['status' => 'current_price_below_estimate'];
            }
        }
        $budget = intdiv($capital, $allowance);
        $baseline = $this->project($fixtures, 10000, 10000000000);
        if (! $baseline['complete']) {
            return ['status' => 'calculation_range_limit'];
        }
        $target = intdiv(10000 * $capital, $allowance * $baseline['total_minor']);
        $plan = $this->project($fixtures, $target, $budget);
        if (! $plan['complete']) {
            $low = 1;
            $high = $target;
            $target = 0;
            while ($low <= $high) {
                $trial = intdiv($low + $high, 2);
                if ($this->project($fixtures, $trial, $budget)['complete']) {
                    $target = $trial;
                    $low = $trial + 1;
                } else {
                    $high = $trial - 1;
                }
            }
            $plan = $this->project($fixtures, $target, $budget);
        }
        if ($target < 1 || ! $plan['complete']) {
            return ['status' => 'insufficient_budget'];
        }

        return ['status' => 'estimated', 'policy_version' => self::VERSION, 'capital_minor' => $capital,
            'allowance_multiplier' => $allowance, 'allowance_minor' => $plan['total_minor'] * $allowance,
            'profit_target_minor' => $target, 'baseline' => $baseline, 'plan' => $plan,
            'basis' => 'current_first_quote_and_known_earlier_season_estimates', 'balance_verified' => false];
    }

    private function project(array $fixtures, int $profit, int $budget): array
    {
        $previous = $spent = 0;
        $rows = [];
        foreach ($fixtures as $i => $fixture) {
            $denominator = ($fixture['estimated_odds_scaled'] - 10000) * 100;
            $stake = max(10000, intdiv(($previous + $profit) * 10000 + $denominator - 1, $denominator) * 100);
            if ($stake > $budget - $spent) {
                return ['complete' => false, 'total_minor' => $spent, 'attempts' => $rows];
            }
            $spent += $stake;
            $payoutPrice = $i === 0 ? $fixture['current_odds_scaled'] : $fixture['estimated_odds_scaled'];
            $payout = intdiv($stake * $payoutPrice, 10000);
            $rows[] = ['attempt' => $i + 1, 'matchday' => $fixture['matchday'], 'stake_minor' => $stake,
                'estimated_odds_scaled' => $fixture['estimated_odds_scaled'], 'payout_basis' => $i === 0 ? 'current_quote' : 'estimate',
                'payout_odds_scaled' => $payoutPrice, 'potential_payout_minor' => $payout,
                'total_staked_minor' => $spent, 'net_if_won_minor' => $payout - $spent];
            $previous = $payout;
        }

        return ['complete' => true, 'total_minor' => $spent, 'attempts' => $rows];
    }
}
