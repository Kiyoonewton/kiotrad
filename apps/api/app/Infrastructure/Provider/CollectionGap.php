<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;

final class CollectionGap
{
    public function detect(string $competition, array $previous, string $now, bool $failed): ?array
    {
        if (isset($previous['collection_gap'])) {
            return $previous['collection_gap'];
        }
        $due = $previous['next_attempt_at'] ?? $now;
        $late = CarbonImmutable::parse($due)->diffInSeconds(CarbonImmutable::parse($now), false) > 60;
        if (! $failed && ! $late) {
            return null;
        }

        return ['id' => hash('sha256', $competition.':'.$due.':'.($previous['collection_observed_at'] ?? 'unknown')),
            'expected_at' => $due, 'last_observed_at' => $previous['collection_observed_at'] ?? null,
            'detected_at' => $now, 'reason' => $failed ? 'collection_cycle_failed' : 'overdue_collection',
            'status' => 'interrupted'];
    }

    public function resumed(array $gap, array $schedule, string $now): array
    {
        return [...$gap, 'status' => 'collection_resumed', 'resumed_at' => $gap['resumed_at'] ?? $now,
            'pending_season_ids' => array_map('strval', array_keys($schedule['pending_seasons'] ?? [])),
            'reconciliation_status' => empty($schedule['pending_seasons']) ? 'queue_empty' : 'pending',
            'opportunities_replayed' => false];
    }
}
