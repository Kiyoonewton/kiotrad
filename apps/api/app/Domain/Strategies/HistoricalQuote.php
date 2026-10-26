<?php

namespace App\Domain\Strategies;

use App\Domain\Paper\RecoveryStake;
use DomainException;

final class HistoricalQuote
{
    public function make(array $captures, array $fixture, string $team, int $losses, int $attempt, int $cash, string $asOf): array
    {
        $outcome = $fixture['home_team_uid'] === $team ? 'H' : 'A';
        $candidates = [];
        $values = [];
        foreach ($captures as $capture) {
            $matches = array_values(array_filter($capture['matches'], fn ($m) => $m['provider_match_id'] === $fixture['provider_match_id']));
            if (count($matches) !== 1 || $matches[0]['home_team_uid'] !== $fixture['home_team_uid']
                || $matches[0]['away_team_uid'] !== $fixture['away_team_uid'] || $matches[0]['scheduled_start_at'] !== $fixture['scheduled_start_at']) {
                continue;
            }
            foreach ($capture['odds_snapshots'] ?? [] as $price) {
                if ($price['provider_match_id'] !== $fixture['provider_match_id'] || $price['captured_at'] > $asOf) {
                    continue;
                }
                $odds = (string) ($price['prices'][$outcome] ?? '');
                $values[$price['captured_at']][$odds] = true;
                $candidates[] = [...$price, 'capture_id' => $capture['_id'], 'stored_at' => $capture['observed_at']];
            }
        }
        usort($candidates, fn ($a, $b) => [$a['captured_at'], $a['stored_at'], $a['capture_id']] <=> [$b['captured_at'], $b['stored_at'], $b['capture_id']]);
        foreach ($candidates as $price) {
            if (count($values[$price['captured_at']]) !== 1) {
                continue;
            }
            try {
                $quote = (new RecoveryStake)->calculate((string) ($price['prices'][$outcome] ?? ''), $losses, $attempt, $cash);
            } catch (DomainException $exception) {
                if ($exception->getMessage() === 'capital_limit') {
                    return ['reason' => 'capital_limit'];
                }

                continue;
            }

            return [...$quote, 'outcome' => $outcome, 'decided_at' => null,
                'simulated_entry_at' => $fixture['scheduled_start_at'], 'scheduled_start_at' => $fixture['scheduled_start_at'],
                'price_provenance' => $price['provenance'] ?? null,
                'price_capture_id' => $price['capture_id'], 'price_observed_at' => $price['captured_at'],
                'price_timing_verified' => ($price['pre_match_verified'] ?? false) && $price['captured_at'] < $fixture['scheduled_start_at'],
                'home_team_uid' => $fixture['home_team_uid'], 'away_team_uid' => $fixture['away_team_uid']];
        }

        return ['reason' => 'awaiting_recorded_odds'];
    }
}
