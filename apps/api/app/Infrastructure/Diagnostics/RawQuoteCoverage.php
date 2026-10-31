<?php

namespace App\Infrastructure\Diagnostics;

final class RawQuoteCoverage
{
    public function inspect($db, string $league, string $season, array $prices): array
    {
        $known = [];
        if ($prices !== []) {
            $rows = $db->provider_captures->find(['competition' => $league, 'season_id' => $season,
                'odds_snapshots.provider_match_id' => ['$in' => array_column($prices, 'provider_match_id')]],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'projection' => ['odds_snapshots' => 1]]);
            foreach ($rows as $row) {
                foreach ($row['odds_snapshots'] ?? [] as $quote) {
                    $key = $this->key($quote['prices'] ?? []);
                    if ($key !== null) {
                        $known[$quote['provider_match_id']][$key] = true;
                    }
                }
            }
        }
        $counts = ['source_prices' => count($prices), 'matching_existing' => 0, 'different_existing' => 0, 'missing' => 0];
        foreach ($prices as $price) {
            $id = $price['provider_match_id'];
            $status = isset($known[$id][$this->key($price['prices'])]) ? 'matching_existing' :
                (empty($known[$id]) ? 'missing' : 'different_existing');
            $counts[$status]++;
        }

        return $counts;
    }

    private function key(array $prices): ?string
    {
        $values = [];
        foreach (['H', 'D', 'A'] as $code) {
            if (! isset($prices[$code]) || ! is_numeric($prices[$code]) || ! is_finite((float) $prices[$code]) || (float) $prices[$code] <= 1) {
                return null;
            }
            $values[] = (string) (float) $prices[$code];
        }

        return implode(':', $values);
    }
}
