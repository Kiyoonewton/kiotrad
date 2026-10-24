<?php

namespace App\Domain\Strategies;

final class SimulationSeasonLinks
{
    public function merge(array $links, array $catalog, string $observedAt): array
    {
        $unique = [];
        foreach ($links as $link) {
            if (in_array($link['continuity_status'] ?? '', ['observed_rollover', 'provider_catalog_adjacency'], true)) {
                $key = $link['from_season_id'].':'.$link['to_season_id'];
                if (! isset($unique[$key]) || $unique[$key]['observed_at'] > $link['observed_at']) {
                    $unique[$key] = $link;
                }
            }
        }
        $links = array_values($unique);
        $valid = [];
        $starts = [];
        foreach ($catalog as $season) {
            if (! is_int($season['start_uts'] ?? null) || ! ctype_digit((string) ($season['season_id'] ?? ''))
                || isset($starts[$season['start_uts']]) || isset($valid[$season['season_id']])) {
                return $links; // Ambiguous metadata must not manufacture season order.
            }
            $starts[$season['start_uts']] = true;
            $valid[$season['season_id']] = $season;
        }
        $catalog = array_values($valid);
        usort($catalog, fn ($a, $b) => $a['start_uts'] <=> $b['start_uts']);
        for ($i = 1; $i < count($catalog); $i++) {
            $to = (string) $catalog[$i]['season_id'];
            $from = (string) $catalog[$i - 1]['season_id'];
            if (array_filter($links, fn ($l) => $l['from_season_id'] === $from && $l['to_season_id'] === $to)) {
                continue;
            }
            $links = array_values(array_filter($links, fn ($l) => $l['to_season_id'] !== $to));
            $links[] = ['from_season_id' => $from, 'to_season_id' => $to,
                'observed_at' => max($catalog[$i - 1]['observed_at'] ?? $observedAt, $catalog[$i]['observed_at'] ?? $observedAt),
                'continuity_status' => 'provider_catalog_adjacency'];
        }

        return $links;
    }
}
