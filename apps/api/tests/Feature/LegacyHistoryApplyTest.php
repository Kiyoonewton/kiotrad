<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryComparison;
use App\Infrastructure\Provider\LegacyHistoryPlan;
use App\Infrastructure\Provider\LegacyHistoryWriter;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LegacyHistoryApplyTest extends TestCase
{
    private function source(string $hash = 'a', array $prices = []): array
    {
        return ['competition' => 'virtual-euro', 'season_id' => '10', 'source_sha256' => $hash,
            'matches' => [['provider_match_id' => 'vf:match:99', 'season_id' => '10', 'matchday' => 1,
                'home_team_uid' => '1', 'home_team' => 'Home', 'away_team_uid' => '2', 'away_team' => 'Away',
                'scheduled_start_at' => null, 'time_source' => null, 'home_score' => null, 'away_score' => null,
                'result' => null, 'status' => 'scheduled_or_unconfirmed', 'prices' => $prices, 'market_status' => -3]]];
    }

    private function plan(array $sources, array $captures = [], array $existing = []): array
    {
        $rows = app(LegacyHistoryComparison::class)->compare($sources, $captures);

        return app(LegacyHistoryPlan::class)->build($sources, $rows, '2026-10-27T12:00:00Z', $existing);
    }

    public function test_partial_new_history_has_no_invented_result_score_or_timing(): void
    {
        $plan = $this->plan([$this->source()]);
        $this->assertSame(1, $plan['new_matches']);
        $this->assertSame(0, $plan['new_prices']);
        $this->assertNull($plan['capture']['matches'][0]['result']);
        $this->assertNull($plan['capture']['matches'][0]['scheduled_start_at']);
        $this->assertNull($plan['capture']['matches'][0]['home_score']);
    }

    public function test_missing_prices_clone_existing_verified_fixture_without_downgrading_it(): void
    {
        $source = $this->source('a', ['H' => '2', 'D' => '3', 'A' => '4']);
        $match = [...$source['matches'][0], 'scheduled_start_at' => '2026-10-20T10:00:00Z',
            'time_source' => 'stats_season_fixtures2.time.uts', 'home_score' => 2, 'away_score' => 0,
            'result' => 'H', 'status' => 'settled'];
        unset($match['prices'], $match['market_status']);
        $plan = $this->plan([$source], [['competition' => 'virtual-euro', 'matches' => [$match]]], ['vf:match:99' => $match]);
        $this->assertSame(0, $plan['new_matches']);
        $this->assertSame(1, $plan['new_prices']);
        $this->assertSame($match, $plan['capture']['matches'][0]);
        $this->assertFalse($plan['capture']['odds_snapshots'][0]['pre_match_verified']);
        $this->assertNull($plan['capture']['odds_snapshots'][0]['provenance']['original_received_at']);
    }

    public function test_mixed_new_and_existing_fixtures_preserve_the_existing_fixture_lookup(): void
    {
        $source = $this->source('a', ['H' => '2', 'D' => '3', 'A' => '4']);
        $match = $source['matches'][0];
        unset($match['prices'], $match['market_status']);
        $match['scheduled_start_at'] = '2026-10-20T10:00:00Z';
        $new = $source['matches'][0];
        $new['provider_match_id'] = 'vf:match:100';
        $source['matches'] = [$new, $source['matches'][0]];
        $plan = $this->plan([$source], [['competition' => 'virtual-euro', 'matches' => [$match]]], ['vf:match:99' => $match]);
        $this->assertSame(1, $plan['new_matches']);
        $this->assertSame(2, $plan['new_prices']);
        $matches = array_column($plan['capture']['matches'], null, 'provider_match_id');
        $this->assertSame($match, $matches['vf:match:99']);
        $this->assertNull($matches['vf:match:100']['scheduled_start_at']);
    }

    public function test_matching_existing_price_produces_no_new_records(): void
    {
        $source = $this->source('a', ['H' => '2', 'D' => '3', 'A' => '4']);
        $plan = $this->plan([$source], [['competition' => 'virtual-euro', 'matches' => $source['matches'],
            'odds_snapshots' => [['provider_match_id' => 'vf:match:99', 'prices' => $source['matches'][0]['prices']]]]]);
        $this->assertSame(0, $plan['new_matches']);
        $this->assertSame(0, $plan['new_prices']);
        $this->assertSame([], $plan['capture']['matches']);
    }

    public function test_ambiguous_source_prices_are_retained_raw_without_arbitrary_selection(): void
    {
        $a = $this->source('a', ['H' => '2', 'D' => '3', 'A' => '4']);
        $b = $this->source('b', ['H' => '2.1', 'D' => '3', 'A' => '4']);
        $plan = $this->plan([$a, $b]);
        $this->assertSame(1, $plan['new_matches']);
        $this->assertSame(0, $plan['new_prices']);
        $this->assertSame(['vf:match:99'], $plan['ambiguous_price_match_ids']);
    }

    public function test_conflicting_identity_blocks_the_season_plan(): void
    {
        $a = $this->source();
        $b = $a;
        $b['matches'][0]['home_team_uid'] = '3';
        $this->expectException(RuntimeException::class);
        $this->plan([$a, $b]);
    }

    public function test_writer_uses_insert_only_capture_and_preserves_existing_checkpoint_pointer(): void
    {
        $plan = $this->plan([$this->source()]);
        $ledger = Mockery::mock();
        $captures = Mockery::mock();
        $checkpoints = Mockery::mock();
        $ledger->shouldReceive('updateOne')->once()->with(['_id' => 'key'], ['$setOnInsert' => [
            'status' => 'prepared', 'plan' => $plan]], ['upsert' => true]);
        $ledger->shouldReceive('findOne')->once()->andReturn(['status' => 'prepared', 'plan' => $plan]);
        $captures->shouldReceive('updateOne')->once()->with(['_id' => hash('sha256', 'key')],
            ['$setOnInsert' => $plan['capture']], ['upsert' => true]);
        $checkpoints->shouldReceive('updateOne')->once()->with(['_id' => 'virtual-euro:10'], ['$setOnInsert' => [
            'capture_id' => hash('sha256', 'key'), 'observed_at' => '2026-10-27T12:00:00Z', 'season_id' => '10',
        ]], ['upsert' => true]);
        $checkpoints->shouldReceive('updateOne')->once()->with(['_id' => 'virtual-euro:10'], ['$inc' => ['odds_evidence_revision' => 1]]);
        $ledger->shouldReceive('updateOne')->once()->with(['_id' => 'key'], ['$set' => ['status' => 'complete']]);
        $db = (object) ['local_history_imports' => $ledger, 'provider_captures' => $captures, 'season_checkpoints' => $checkpoints];
        $result = app(LegacyHistoryWriter::class)->apply($db, 'key', $plan);
        $this->assertSame('imported', $result['status']);
    }

    public function test_completed_import_is_idempotent_without_capture_or_checkpoint_access(): void
    {
        $ledger = Mockery::mock();
        $ledger->shouldReceive('updateOne')->once();
        $ledger->shouldReceive('findOne')->once()->andReturn(['status' => 'complete']);
        $result = app(LegacyHistoryWriter::class)->apply((object) ['local_history_imports' => $ledger], 'key', []);
        $this->assertSame('already_imported', $result['status']);
    }

    public function test_production_cannot_reach_import_or_redis_lock(): void
    {
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:import-legacy-history', ['folder' => '/not-read', '--apply' => true])->assertFailed();
    }
}
