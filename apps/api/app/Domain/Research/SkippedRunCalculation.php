<?php

namespace App\Domain\Research;

final class SkippedRunCalculation
{
    public function append(array $rows, string $asOf, int $maxAttempts, int $multiplier, string $model, int $capital, string $capitalMode): array
    {
        foreach ($rows as &$row) {
            if (! str_starts_with($row['status'], 'skipped_')) {
                continue;
            }
            $sizing = $capitalMode === 'fixed' ? $capital : ($row['sizing_capital_at_entry_minor'] ?? $row['capital_at_entry_minor'] ?? $capital);
            if ($sizing < 1 || $sizing > 1000000000) {
                $row['hypothetical'] = ['status' => 'unavailable', 'reason' => 'invalid_sizing_capital'];

                continue;
            }
            $odds = array_column($row['fixtures'], 'historical_odds_scaled');
            if (count($odds) !== count($row['fixtures']) || in_array(null, $odds, true)) {
                $row['hypothetical'] = ['status' => 'unavailable', 'reason' => 'missing_historical_odds'];

                continue;
            }
            $estimate = $row['estimate'] ?? ($model === 'payout_carry_v1'
                ? (new PayoutCarryPlan)->calculate($row['fixtures'], $sizing, $multiplier)
                : (new RunBudgetEstimate)->calculate($odds, $sizing, $multiplier));
            $row['hypothetical'] = $estimate['status'] === 'estimated'
                ? ['estimate' => $estimate, 'sizing_capital_at_entry_minor' => $sizing,
                    ...(new SharedRunSettlement)->calculate($row, $estimate, $asOf, $row['attempt_limit'] ?? $maxAttempts)]
                : ['status' => 'unavailable', 'reason' => $estimate['status']];
        }
        unset($row);

        return $rows;
    }
}
