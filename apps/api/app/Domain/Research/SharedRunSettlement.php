<?php

namespace App\Domain\Research;

final class SharedRunSettlement
{
    public function calculate(array $candidate, array $estimate, string $asOf): array
    {
        $profit = $estimate['plan']['profit_per_attempt_minor'];
        $allowance = $estimate['allowance_minor'];
        $spent = 0;
        $attempts = [];
        $lastConfirmation = null;
        foreach ($candidate['fixtures'] as $index => $fixture) {
            $start = $fixture['scheduled_start_at'];
            if ($start > $asOf) {
                return $this->view('awaiting_fixture', null, $spent, 0, $attempts);
            }
            if ($lastConfirmation !== null && $lastConfirmation >= $start) {
                return $this->view(isset($fixture['simulation_settlement_at']) ? 'timing_model_entry_stop' : 'unresolved_confirmation_timing',
                    isset($fixture['simulation_settlement_at']) ? $lastConfirmation : null, $spent, 0, $attempts);
            }
            $price = $fixture['actual_odds_scaled'] ?? null;
            if (! is_int($price) || $price <= 10000 || $price > 10000000 || ! empty($fixture['market_blocked'])) {
                return $this->view('awaiting_price_or_market_resolution', null, $spent, 0, $attempts);
            }
            $target = $spent + ($index + 1) * $profit;
            $divisor = ($price - 10000) * 100;
            $stake = max(10000, intdiv($target * 10000 + $divisor - 1, $divisor) * 100);
            if ($spent + $stake > $allowance) {
                return $this->view('allowance_stop', $start, $spent, 0, $attempts);
            }
            $spent += $stake;
            $confirmation = $fixture['simulation_settlement_at'] ?? $fixture['result_confirmed_at'] ?? null;
            $won = $fixture['won'] ?? null;
            $attempts[] = [...$fixture, 'attempt' => $index + 1, 'stake_minor' => $stake,
                'target_profit_minor' => ($index + 1) * $profit, 'payout_minor' => null];
            if ($confirmation === null || $confirmation < $start || $confirmation > $asOf || ! is_bool($won)) {
                return $this->view('awaiting_result', null, $spent, 0, $attempts);
            }
            $lastConfirmation = $confirmation;
            $payout = $won ? intdiv($stake * $price, 10000) : 0;
            $attempts[$index]['payout_minor'] = $payout;
            if ($won) {
                return $this->view('win', $confirmation, $spent, $payout, $attempts);
            }
        }

        return $this->view(count($attempts) === 4 ? 'four_losses' : 'season_end', $lastConfirmation, $spent, 0, $attempts);
    }

    private function view(string $status, ?string $ended, int $spent, int $payout, array $attempts): array
    {
        return ['status' => $status, 'released_at' => $ended, 'total_staked_minor' => $spent,
            'gross_payout_minor' => $payout, 'net_minor' => $ended === null ? null : $payout - $spent,
            'attempts' => $attempts];
    }
}
