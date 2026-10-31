<?php

namespace Tests\Feature;

use App\Domain\Strategies\SimulationDefinitionSnapshot;
use App\Infrastructure\History\ProductionHistoryProof;
use RuntimeException;
use Tests\TestCase;

class ProductionHistoryProofTest extends TestCase
{
    public function test_review_requires_complete_inventory_matching_code_and_exact_backup_linkage(): void
    {
        $root = sys_get_temp_dir().'/review-proof-'.bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root.'/manifest.json', '{}');
        $snapshot = (new SimulationDefinitionSnapshot)->current();
        $plan = ['version' => 'production-history-plan-1.0.0', 'mode' => 'read_only_production_plan',
            'applied' => false, 'blocked_seasons' => [], 'seasons' => [[]], 'fixtures' => [],
            'provenance' => ['manifest_sha256' => hash('sha256', '{}'),
                'inventory_completion' => ['applied' => false, 'seasons' => 1],
                'local_code_hashes' => array_reverse($snapshot['code_hashes'], true)],
            'strategy_alignment' => ['production_replay_version' => $snapshot['replay_version'],
                'production_capital_minor' => $snapshot['capital_minor']]];
        $backup = ['version' => 'production-pre-import-backup-1.0.0', 'complete' => true,
            'database_restore_verified' => true, 'production_writes' => false, 'production_database' => 'kiotrad',
            'archives' => [['synthetic' => true]], 'raw_evidence' => ['files' => 1], 'last_good_views' => [['synthetic' => true]]];
        try {
            foreach (['valid', 'wrong_code', 'wrong_capital', 'incomplete_inventory', 'wrong_backup', 'unverified_restore'] as $case) {
                $current = $plan;
                $attestation = $backup;
                if ($case === 'wrong_code') {
                    $current['provenance']['local_code_hashes']['RunReplay.php'] = 'changed';
                } elseif ($case === 'wrong_capital') {
                    $current['strategy_alignment']['production_capital_minor']++;
                } elseif ($case === 'incomplete_inventory') {
                    unset($current['provenance']['inventory_completion']);
                }
                file_put_contents($root.'/review.json', json_encode($current, JSON_THROW_ON_ERROR));
                $attestation['plan_sha256'] = $case === 'wrong_backup' ? 'different' : hash_file('sha256', $root.'/review.json');
                if ($case === 'unverified_restore') {
                    $attestation['database_restore_verified'] = false;
                }
                file_put_contents($root.'/backup.json', json_encode($attestation, JSON_THROW_ON_ERROR));
                try {
                    $proof = app(ProductionHistoryProof::class)->read($root, $root.'/review.json', $root.'/backup.json');
                    $this->assertSame('valid', $case, 'Invalid review accepted: '.$case);
                    $this->assertSame(hash_file('sha256', $root.'/review.json'), $proof['plan_sha256']);
                } catch (RuntimeException $exception) {
                    $this->assertNotSame('valid', $case, $exception->getMessage());
                }
            }
        } finally {
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
