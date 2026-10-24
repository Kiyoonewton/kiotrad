<?php

namespace App\Infrastructure\Provider;

final class GapSeasonPlan
{
    public function intermediate(array $previous, array $seasons, string $currentId, ?int $currentStart): array
    {
        $from = $previous['current_season_start_uts'] ?? null;
        if (! is_int($from) || $currentStart === null || ($previous['current_season_id'] ?? $currentId) === $currentId) {
            return [];
        }
        $rows = array_filter($seasons, fn ($s) => is_int($s['start']['uts'] ?? null)
            && $s['start']['uts'] > $from && $s['start']['uts'] < $currentStart);
        usort($rows, fn ($a, $b) => $a['start']['uts'] <=> $b['start']['uts']);

        return array_map(fn ($s) => (string) $s['_id'], $rows);
    }
}
