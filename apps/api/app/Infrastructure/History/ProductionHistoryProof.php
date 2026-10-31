<?php

namespace App\Infrastructure\History;

use App\Domain\Strategies\SimulationDefinitionSnapshot;
use RuntimeException;

final class ProductionHistoryProof
{
    public function read(string $folder, string $planPath, string $backupPath): array
    {
        $plan = json_decode(file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
        $backup = json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest = hash_file('sha256', $folder.'/manifest.json');
        $snapshot = (new SimulationDefinitionSnapshot)->current();
        $hashes = $plan['provenance']['local_code_hashes'] ?? [];
        ksort($hashes);
        if (($plan['version'] ?? '') !== 'production-history-plan-1.0.0' || ($plan['mode'] ?? '') !== 'read_only_production_plan'
            || ($plan['applied'] ?? null) !== false || ($plan['provenance']['inventory_completion']['applied'] ?? null) !== false
            || ($plan['provenance']['inventory_completion']['seasons'] ?? null) !== count($plan['seasons'] ?? [])
            || ($plan['blocked_seasons'] ?? null) !== [] || ($plan['provenance']['manifest_sha256'] ?? '') !== $manifest
            || $hashes !== $snapshot['code_hashes']
            || ($plan['strategy_alignment']['production_replay_version'] ?? '') !== $snapshot['replay_version']
            || ($plan['strategy_alignment']['production_capital_minor'] ?? null) !== $snapshot['capital_minor']) {
            throw new RuntimeException('Package or current production strategy/settings differ from the reviewed plan.');
        }
        $planHash = hash_file('sha256', $planPath);
        if (($backup['version'] ?? '') !== 'production-pre-import-backup-1.0.0' || ($backup['complete'] ?? false) !== true
            || ($backup['database_restore_verified'] ?? false) !== true || ($backup['production_writes'] ?? null) !== false
            || ($backup['production_database'] ?? '') !== 'kiotrad' || ($backup['plan_sha256'] ?? '') !== $planHash
            || empty($backup['archives']) || empty($backup['raw_evidence']['files']) || empty($backup['last_good_views'])) {
            throw new RuntimeException('A completed isolated-restore backup attestation for this exact plan is required.');
        }
        $actions = [];
        foreach ($plan['fixtures'] ?? [] as $row) {
            $key = $row['league'].':'.$row['season_id'];
            $actions[$key] ??= ['matches' => [], 'prices' => []];
            if ($row['fixture_action'] === 'append_fixture_candidate') {
                $actions[$key]['matches'][] = $row['fixture_id'];
            }
            if ($row['price_action'] === 'append_price_candidate') {
                $actions[$key]['prices'][] = $row['fixture_id'];
            }
        }
        foreach ($actions as &$ids) {
            sort($ids['matches']);
            sort($ids['prices']);
            $ids = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
        }
        unset($ids);

        // Large per-fixture audit tables are not kept in the importer's working set.
        return ['manifest_sha256' => $manifest, 'plan_sha256' => $planHash, 'backup_sha256' => hash_file('sha256', $backupPath),
            'season_actions' => $actions,
            'expected_matches' => $plan['fixture_action_counts']['append_fixture_candidate'] ?? 0,
            'expected_prices' => $plan['price_action_counts']['append_price_candidate'] ?? 0];
    }
}
