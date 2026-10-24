<?php

namespace App\Domain\Paper;

use Carbon\CarbonImmutable;
use DomainException;

final class WinQuote
{
    public function make(array $fixture, ?array $price, string $team, int $losses, int $attempt, int $cash, string $now): array
    {
        if (! in_array($team, [$fixture['home_team_uid'], $fixture['away_team_uid']], true)) {
            throw new DomainException('team_mismatch');
        }
        $start = $fixture['scheduled_start_at'] ?? null;
        if (! $start || $start <= $now || $fixture['status'] !== 'scheduled_or_unconfirmed') {
            throw new DomainException('entry_closed');
        }
        if (! $price || ($price['pre_match_verified'] ?? false) !== true || $price['provider_match_id'] !== $fixture['provider_match_id']
            || $price['captured_at'] > $now || $price['captured_at'] >= $start
            || ($price['stored_at'] ?? $now) > $now) {
            throw new DomainException('unverified_price');
        }
        $age = CarbonImmutable::parse($price['captured_at'])->diffInSeconds(CarbonImmutable::parse($now), false);
        if ($age < 0 || $age > 300) {
            throw new DomainException('stale_price');
        }
        $outcome = $fixture['home_team_uid'] === $team ? 'H' : 'A';
        $odds = (string) ($price['prices'][$outcome] ?? '');

        return [...(new RecoveryStake)->calculate($odds, $losses, $attempt, $cash), 'outcome' => $outcome,
            'price_capture_id' => $price['capture_id'], 'price_observed_at' => $price['captured_at']];
    }
}
