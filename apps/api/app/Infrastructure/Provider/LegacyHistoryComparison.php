<?php

namespace App\Infrastructure\Provider;

final class LegacyHistoryComparison
{
    public function compare(array $incoming, iterable $captures): array
    {
        $known = [];
        $quotes = [];
        foreach ($captures as $capture) {
            foreach ($capture['matches'] ?? [] as $match) {
                $identity = array_intersect_key($match, array_flip(['season_id', 'matchday', 'home_team_uid', 'away_team_uid', 'result', 'scheduled_start_at']));
                $key = hash('sha256', serialize([$capture['competition'] ?? null, $identity]));
                $known[$match['provider_match_id']][$key] = [$capture['competition'] ?? null, $identity];
            }
            foreach ($capture['odds_snapshots'] ?? [] as $quote) {
                if (count($quote['prices'] ?? []) === 3) {
                    $key = hash('sha256', serialize($quote['prices']));
                    $quotes[$quote['provider_match_id']][$key] = $quote['prices'];
                }
            }
        }
        $rows = [];
        $sourceRows = [];
        foreach ($incoming as $source) {
            foreach ($source['matches'] as $match) {
                $id = $match['provider_match_id'];
                $conflicts = [];
                foreach ($sourceRows[$id] ?? [] as $previous) {
                    $conflicts = [...$conflicts, ...$this->conflicts($match, $previous)];
                }
                $sourceRows[$id][] = $match;
                $existing = $known[$id] ?? [];
                foreach ($existing as [$competition, $old]) {
                    if ($competition !== $source['competition']) {
                        $conflicts[] = 'competition';
                    }
                    $conflicts = [...$conflicts, ...$this->conflicts($match, $old)];
                }
                $prices = $match['prices'];
                $oldPrices = $quotes[$id] ?? [];
                $priceStatus = $prices === [] ? 'source_price_missing' : ($oldPrices === [] ? 'missing_in_database' : 'different_recorded_price');
                foreach ($oldPrices as $old) {
                    if ($prices !== [] && $this->samePrices($prices, $old)) {
                        $priceStatus = 'matching';
                    }
                }
                $rows[] = ['provider_match_id' => $id, 'matchday' => $match['matchday'],
                    'source_sha256' => $source['source_sha256'],
                    'fixture_status' => $existing === [] ? 'new_fixture' : 'existing_fixture',
                    'price_status' => $priceStatus, 'conflicts' => array_values(array_unique($conflicts)),
                    'timing_status' => array_filter($existing, fn ($row) => ! empty($row[1]['scheduled_start_at'])) ? 'stored_schedule_available' : 'unknown',
                    'result_available' => $match['result'] !== null];
            }
        }
        // Flag every version of a conflicting source match, not only the later file.
        $conflictsById = [];
        foreach ($rows as $row) {
            $conflictsById[$row['provider_match_id']] = array_values(array_unique([
                ...($conflictsById[$row['provider_match_id']] ?? []), ...$row['conflicts']]));
        }
        foreach ($rows as &$row) {
            $row['conflicts'] = $conflictsById[$row['provider_match_id']];
        }
        unset($row);

        return $rows;
    }

    private function conflicts(array $a, array $b): array
    {
        $conflicts = [];
        foreach (['season_id', 'matchday', 'home_team_uid', 'away_team_uid'] as $key) {
            if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) {
                $conflicts[] = $key;
            }
        }
        if (! empty($a['result']) && ! empty($b['result']) && $a['result'] !== $b['result']) {
            $conflicts[] = 'result';
        }

        return $conflicts;
    }

    private function samePrices(array $a, array $b): bool
    {
        foreach (['H', 'D', 'A'] as $code) {
            if (! isset($a[$code], $b[$code]) || (float) $a[$code] !== (float) $b[$code]) {
                return false;
            }
        }

        return true;
    }
}
