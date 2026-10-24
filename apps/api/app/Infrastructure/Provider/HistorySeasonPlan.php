<?php

namespace App\Infrastructure\Provider;

final class HistorySeasonPlan
{
    public function previous(array $seasons, int $currentStart, int $limit): array
    {
        $older = array_values(array_filter($seasons, fn ($s) => is_int($s['start']['uts'] ?? null)
            && $s['start']['uts'] < $currentStart && ctype_digit((string) ($s['_id'] ?? ''))));
        usort($older, fn ($a, $b) => $b['start']['uts'] <=> $a['start']['uts']);
        $ids = array_values(array_unique(array_map(fn ($s) => (string) $s['_id'], $older)));

        return array_reverse(array_slice($ids, 0, max(0, $limit)));
    }
}
