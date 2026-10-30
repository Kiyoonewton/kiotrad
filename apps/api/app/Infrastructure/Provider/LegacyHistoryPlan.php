<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class LegacyHistoryPlan
{
    public const VERSION = 'legacy-history-import-1.0.0';

    public function build(array $sources, array $comparison, string $at, array $existing = []): array
    {
        if (array_filter($comparison, fn ($row) => $row['conflicts'] !== [])) {
            throw new RuntimeException('Identity or result conflicts require review; season not imported.');
        }
        $lookup = [];
        foreach ($comparison as $row) {
            $lookup[$row['source_sha256'].':'.$row['provider_match_id']] = $row;
        }
        $matches = [];
        $prices = [];
        $variants = [];
        foreach ($sources as $source) {
            foreach ($source['matches'] as $match) {
                $id = $match['provider_match_id'];
                $row = $lookup[$source['source_sha256'].':'.$id];
                if ($row['fixture_status'] === 'new_fixture') {
                    $priorMatch = $matches[$id] ?? null;
                    if ($priorMatch === null || ($priorMatch['result'] === null && $match['result'] !== null)) {
                        $matches[$id] = array_diff_key($match, array_flip(['prices', 'price_status', 'market_status']));
                    }
                }
                if ($row['price_status'] !== 'missing_in_database') {
                    continue;
                }
                $key = implode(':', array_map(fn ($code) => (string) (float) $match['prices'][$code], ['H', 'D', 'A']));
                $variants[$id][$key] = true;
                $prices[$id] = ['provider_match_id' => $id, 'market' => '1X2', 'prices' => $match['prices'],
                    'captured_at' => $at, 'provider_response_timestamp_ms' => null, 'pre_match_verified' => false,
                    'market_status' => $match['market_status'], 'provenance' => [
                        'origin' => 'legacy_file', 'source_sha256' => $source['source_sha256'],
                        'timestamp_basis' => 'import_time_original_retrieval_unknown', 'original_received_at' => null]];
            }
        }
        $ambiguous = [];
        foreach ($variants as $id => $values) {
            if (count($values) > 1) {
                unset($prices[$id]);
                $ambiguous[] = $id; // Preserve every raw version, select no arbitrary price.
            }
        }
        $newMatchCount = count($matches);
        foreach (array_keys($prices) as $id) {
            if (! isset($matches[$id])) {
                if (! isset($existing[$id])) {
                    throw new RuntimeException('Existing fixture is required to attach missing odds.');
                }
                $matches[$id] = $existing[$id]; // Clone verified evidence, never replace it with unknown timing.
            }
        }
        ksort($matches);
        ksort($prices);
        $hashes = array_values(array_unique(array_column($sources, 'source_sha256')));
        sort($hashes);
        $capture = ['competition' => $sources[0]['competition'], 'season_id' => $sources[0]['season_id'],
            'observed_at' => $at, 'scope' => 'legacy_history_import', 'schema_version' => 1,
            'matches' => array_values($matches), 'odds_snapshots' => array_values($prices),
            'provenance' => ['origin' => 'legacy_history_package', 'version' => self::VERSION,
                'source_sha256s' => $hashes, 'timestamp_basis' => 'import_time_original_retrieval_unknown',
                'timing_status' => 'unknown_for_new_fixtures', 'interruption_cause' => 'not_established']];

        return ['capture' => $capture, 'ambiguous_price_match_ids' => $ambiguous,
            'new_matches' => $newMatchCount, 'new_prices' => count($prices)];
    }
}
