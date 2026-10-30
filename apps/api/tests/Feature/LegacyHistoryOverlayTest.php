<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryWriter;
use Mockery;
use Tests\TestCase;

class LegacyHistoryOverlayTest extends TestCase
{
    private function plan(): array
    {
        return ['new_matches' => 1, 'new_prices' => 0, 'capture' => [
            'competition' => 'virtual-euro', 'season_id' => '10', 'observed_at' => '2026-10-27T12:00:00Z',
        ]];
    }

    public function test_unchanged_overlay_does_not_rewrite_evidence_or_invalidate_caches(): void
    {
        $captures = Mockery::mock();
        $checkpoints = Mockery::mock();
        $captures->shouldReceive('findOne')->once()->andReturn(['_id' => hash('sha256', 'key')]);
        $checkpoints->shouldReceive('findOne')->once()->andReturn(['_id' => 'virtual-euro:10']);
        $captures->shouldReceive('updateOne')->never();
        $checkpoints->shouldReceive('updateOne')->never();
        $result = (new LegacyHistoryWriter)->restoreCompleted((object) ['provider_captures' => $captures, 'season_checkpoints' => $checkpoints], 'key', $this->plan());
        $this->assertFalse($result['restored_materialization']);
    }

    public function test_overlay_restores_missing_pointer_without_overwriting_verified_capture(): void
    {
        $captures = Mockery::mock();
        $checkpoints = Mockery::mock();
        $captures->shouldReceive('findOne')->once()->andReturn(['_id' => hash('sha256', 'key')]);
        $captures->shouldReceive('updateOne')->never();
        $checkpoints->shouldReceive('findOne')->once()->andReturnNull();
        $checkpoints->shouldReceive('updateOne')->once()->with(['_id' => 'virtual-euro:10'], ['$setOnInsert' => [
            'capture_id' => hash('sha256', 'key'), 'observed_at' => '2026-10-27T12:00:00Z',
            'season_id' => '10', 'odds_evidence_revision' => 1,
        ]], ['upsert' => true]);
        $result = (new LegacyHistoryWriter)->restoreCompleted((object) ['provider_captures' => $captures, 'season_checkpoints' => $checkpoints], 'key', $this->plan());
        $this->assertTrue($result['restored_materialization']);
        $this->assertSame(0, $result['new_matches']);
    }
}
