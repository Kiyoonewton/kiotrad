<?php

namespace App\Infrastructure\History;

use App\Infrastructure\Provider\LegacyHistoryComparison;
use App\Infrastructure\Provider\LegacyHistoryPlan;
use RuntimeException;

final class ProductionHistoryAssessment
{
    public function __construct(private LegacyHistoryComparison $comparison, private LegacyHistoryPlan $planner) {}

    public function build(array $sources, iterable $captures, string $at, array $proof): array
    {
        $existing = $times = [];
        $stream = (function () use ($captures, &$existing, &$times, $sources) {
            foreach ($captures as $capture) {
                foreach ($capture['matches'] ?? [] as $match) {
                    $id = $match['provider_match_id'];
                    if (! empty($match['scheduled_start_at'])) {
                        $times[$id][$match['scheduled_start_at']] = true;
                        if (count($times[$id]) > 1) {
                            throw new RuntimeException('Conflicting stored kickoff times: '.$id);
                        }
                    }
                    $rank = fn ($m) => (int) ! empty($m['scheduled_start_at']) * 4
                        + (int) ! empty($m['result']) * 2 + (int) isset($m['home_score'], $m['away_score']);
                    if (! isset($existing[$id]) || $rank($match) > $rank($existing[$id])) {
                        $existing[$id] = $match;
                    }
                }
                foreach ($capture['odds_snapshots'] ?? [] as $quote) {
                    if (($capture['competition'] ?? null) !== $sources[0]['competition']) {
                        throw new RuntimeException('Quote competition conflicts with source identity.');
                    }
                }
                yield $capture;
            }
        })();
        $rows = $this->comparison->compare($sources, $stream);
        $plan = $this->planner->build($sources, $rows, $at, $existing);
        if ($plan['ambiguous_price_match_ids'] !== []) {
            throw new RuntimeException('Ambiguous source prices need a separate reviewed plan.');
        }
        $ids = ['matches' => [], 'prices' => array_column($plan['capture']['odds_snapshots'], 'provider_match_id')];
        foreach ($rows as $row) {
            if ($row['fixture_status'] === 'new_fixture') {
                $ids['matches'][$row['provider_match_id']] = $row['provider_match_id'];
            }
        }
        $ids['matches'] = array_values($ids['matches']);
        sort($ids['matches']);
        sort($ids['prices']);
        $plan['action_sha256'] = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
        $plan['capture']['scope'] = 'production_history_import';
        $plan['capture']['provenance']['production_import_version'] = ProductionHistoryStore::VERSION;
        $plan['capture']['provenance']['reviewed_proof'] = array_intersect_key($proof, array_flip(['manifest_sha256', 'plan_sha256', 'backup_sha256']));

        return $plan;
    }
}
