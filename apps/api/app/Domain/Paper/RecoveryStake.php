<?php

namespace App\Domain\Paper;

use DomainException;

final class RecoveryStake
{
    public function calculate(string $odds, int $losses, int $attempt, int $cash): array
    {
        if (! preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', $odds)) {
            throw new DomainException('invalid_odds');
        }
        [$whole, $fraction] = array_pad(explode('.', $odds), 2, '');
        $scaled = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
        if ($scaled <= 10000 || $scaled > 10000000 || $attempt < 1 || $attempt > 4 || $losses < 0) {
            throw new DomainException('invalid_quote');
        }
        $target = $losses + $attempt * 10000;
        $denominator = ($scaled - 10000) * 100;
        $stake = max(10000, intdiv($target * 10000 + $denominator - 1, $denominator) * 100);
        if ($stake > $cash) {
            throw new DomainException('capital_limit');
        }

        return ['stake_minor' => $stake, 'odds' => $odds, 'odds_scaled' => $scaled,
            'target_profit_minor' => $attempt * 10000, 'potential_payout_minor' => intdiv($stake * $scaled, 10000)];
    }
}
