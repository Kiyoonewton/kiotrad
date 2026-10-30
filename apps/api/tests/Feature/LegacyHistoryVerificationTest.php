<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryVerification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyHistoryVerificationTest extends TestCase
{
    private function record(): array
    {
        return ['_id' => 'key', 'status' => 'complete', 'plan' => ['new_matches' => 1, 'new_prices' => 1,
            'capture' => ['competition' => 'virtual-euro', 'season_id' => '10',
                'matches' => [['provider_match_id' => 'vf:match:1', 'scheduled_start_at' => null]],
                'odds_snapshots' => [['provider_match_id' => 'vf:match:1', 'prices' => ['H' => '2', 'D' => '3', 'A' => '4']]],
                'provenance' => ['source_sha256s' => ['source-hash']]]]];
    }

    public function test_full_evidence_matches_regardless_of_document_key_order(): void
    {
        $record = $this->record();
        $capture = array_reverse($record['plan']['capture'], true);
        $capture['_id'] = 'materialization';
        $row = (new LegacyHistoryVerification)->inspect($record, $capture, true);
        $this->assertSame('verified', $row['status']);
        $this->assertSame($row['expected_sha256'], $row['actual_sha256']);
        $this->assertSame(1, $row['untimed']);
        $this->assertSame(['source-hash'], $row['source_sha256s']);
    }

    public function test_equal_counts_do_not_hide_price_identity_or_provenance_changes(): void
    {
        foreach (['price', 'identity', 'provenance'] as $change) {
            $record = $this->record();
            $capture = $record['plan']['capture'];
            if ($change === 'price') {
                $capture['odds_snapshots'][0]['prices']['H'] = '2.1';
            } elseif ($change === 'identity') {
                $capture['matches'][0]['provider_match_id'] = 'vf:match:2';
            } else {
                $capture['provenance']['source_sha256s'] = ['different-hash'];
            }
            $row = (new LegacyHistoryVerification)->inspect($record, $capture, true);
            $this->assertSame(['capture_differs_from_pinned_plan'], $row['issues']);
        }
    }

    public function test_missing_materialization_or_checkpoint_fails_but_raw_only_plan_needs_neither(): void
    {
        $record = $this->record();
        $verification = new LegacyHistoryVerification;
        $row = $verification->inspect($record, null, false);
        $this->assertSame(['missing_capture', 'missing_season_checkpoint'], $row['issues']);
        $record['plan']['new_matches'] = $record['plan']['new_prices'] = 0;
        $this->assertSame('raw_preserved_only', $verification->inspect($record, null, false)['status']);
        $record['status'] = 'prepared';
        $this->assertSame('failed', $verification->inspect($record, null, false)['status']);
    }

    public function test_production_verification_cannot_access_database(): void
    {
        config(['kiotrad.local_data_mirror' => false]);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:verify-legacy-history')->assertFailed();
    }
}
