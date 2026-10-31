<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\RawOddsRecovery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RawOddsRecoveryTest extends TestCase
{
    use ProviderFixtures;

    private function feed(): FeedObservation
    {
        return new FeedObservation('https://example.invalid/vfc_stats_round_odds2/vf:season:200/1',
            CarbonImmutable::parse('2026-10-24T00:00:00Z'), CarbonImmutable::parse('2026-10-24T00:00:01Z'),
            $this->oddsFixture(), []);
    }

    private function savedFixtures(): array
    {
        $matches = (new FixtureNormalizer)->normalize($this->seasonFixture(), '200', 31867);
        $matches[0]['status'] = 'settled';
        $matches[0]['result'] = 'H';

        return $matches;
    }

    public function test_dry_run_finds_recoverable_raw_prices_without_writes(): void
    {
        config(['provider.competitions' => ['test' => ['tournament_uid' => 31867]]]);
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => isset($q['observed_at'])), Mockery::any())->andReturn(null);
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => ! isset($q['observed_at']) && isset($q['matches.provider_match_id'])), Mockery::any())
            ->andReturn(['matches' => $this->savedFixtures()]);
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => isset($q['odds_snapshots'])), Mockery::any())->andReturn(null);
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['provider_captures' => $collection]);
        $this->assertSame('recoverable_files', app(RawOddsRecovery::class)->one($this->feed(), '200', 1, false)['status']);
    }

    public function test_apply_does_not_backdate_results_and_invalidates_old_evidence_views(): void
    {
        config(['provider.competitions' => ['test' => ['tournament_uid' => 31867]]]);
        $later = $this->savedFixtures();
        $later[0]['scheduled_start_at'] = '2026-10-25T00:00:00Z';
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->with(Mockery::on(fn ($q) => isset($q['observed_at'])), Mockery::any())->andReturn(null);
        $collection->shouldReceive('findOne')->with(Mockery::on(fn ($q) => ! isset($q['observed_at']) && isset($q['matches.provider_match_id'])), Mockery::any())
            ->andReturn(['matches' => $later]);
        $collection->shouldReceive('findOne')->with(Mockery::on(fn ($q) => isset($q['odds_snapshots'])), Mockery::any())->andReturn(null);
        $collection->shouldReceive('updateOne')->once()->with(Mockery::any(), Mockery::on(function ($update) {
            $saved = $update['$setOnInsert'];

            return $saved['observed_at'] === '2026-10-24T00:00:01+00:00'
                && $saved['matches'][0]['result'] === null
                && $saved['odds_snapshots'][0]['pre_match_verified'] === false
                && $saved['odds_snapshots'][0]['prices']['H'] === '2.50';
        }), ['upsert' => true]);
        $checkpoint = Mockery::mock();
        $checkpoint->shouldReceive('updateOne')->once()->with(Mockery::any(), Mockery::any(), ['upsert' => true]);
        $checkpoint->shouldReceive('updateOne')->once()->with(Mockery::on(fn ($q) => isset($q['observed_at'])), Mockery::any());
        $checkpoint->shouldReceive('updateOne')->once()->with(['_id' => 'test:200'], ['$inc' => ['odds_evidence_revision' => 1]]);
        $db = Mockery::mock();
        $db->provider_captures = $collection;
        $db->season_checkpoints = $checkpoint;
        $db->shouldReceive('selectCollection')->with('provider_captures')->andReturn($collection);
        $db->shouldReceive('selectCollection')->with('season_checkpoints')->andReturn($checkpoint);
        DB::shouldReceive('connection->getDatabase')->andReturn($db);
        $this->assertSame('saved_files', app(RawOddsRecovery::class)->one($this->feed(), '200', 1, true)['status']);
    }

    public function test_existing_prices_are_not_inserted_again(): void
    {
        config(['provider.competitions' => ['test' => ['tournament_uid' => 31867]]]);
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => isset($q['observed_at'])), Mockery::any())
            ->andReturn(['matches' => $this->savedFixtures()]);
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => isset($q['odds_snapshots'])), Mockery::any())
            ->andReturn(['_id' => 'existing']);
        $checkpoint = Mockery::mock();
        $checkpoint->shouldReceive('updateOne')->once()->with(['_id' => 'test:200'], ['$inc' => ['odds_evidence_revision' => 1]]);
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['provider_captures' => $collection, 'season_checkpoints' => $checkpoint]);
        $this->assertSame('already_present', app(RawOddsRecovery::class)->one($this->feed(), '200', 1, true)['status']);
    }

    public function test_recovery_refuses_production_before_reading_files(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->expectException(RuntimeException::class);
        app(RawOddsRecovery::class)->recover('/unused', true, fn () => null);
    }
}
