<?php

namespace App\Domain\Research;

final class RunWindowOutcome
{
    public function observe(array $candidate, string $asOf): array
    {
        $unknown = false;
        foreach ($candidate['fixtures'] as $fixture) {
            $confirmed = $fixture['simulation_settlement_at'] ?? $fixture['result_confirmed_at'] ?? null;
            $known = $confirmed !== null && $confirmed >= $fixture['scheduled_start_at'] && $confirmed <= $asOf
                && is_bool($fixture['won'] ?? null);
            if ($known && $fixture['won']) {
                return ['result' => 'win', 'ended_at' => $confirmed, 'result_observed_at' => $fixture['result_confirmed_at'], 'basis' => 'observed_only_no_stakes'];
            }
            $unknown = $unknown || ! $known;
        }
        $last = $candidate['fixtures'][array_key_last($candidate['fixtures'])];

        return ['result' => $unknown ? 'pending' : 'no_win',
            'ended_at' => $unknown ? null : ($last['simulation_settlement_at'] ?? $last['result_confirmed_at']),
            'result_observed_at' => $unknown ? null : $last['result_confirmed_at'], 'basis' => 'observed_only_no_stakes'];
    }
}
