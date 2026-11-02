<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ChampionMonitoring;
use ArrayIterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ChampionMonitoringProjectionTest extends TestCase
{
    private array $captures;

    private array $reads = [];

    private int $bytes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
        config(['kiotrad.simulation_cache_enabled' => false, 'kiotrad.simulation_capital_minor' => 700000]);
        foreach (['10' => '22', '20' => '23', '30' => '24'] as $season => $date) {
            $matches = $prices = [];
            foreach (['D', 'A', 'D', 'A', 'H', 'H'] as $index => $result) {
                $id = 'vf:match:'.$season.$index;
                $matches[] = ['provider_match_id' => $id, 'season_id' => (string) $season, 'matchday' => $index + 1,
                    'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Synthetic champion', 'away_team' => 'Synthetic opponent',
                    'scheduled_start_at' => CarbonImmutable::parse('2026-10-'.$date.'T10:00:00Z')->addMinutes($index * 4)->toIso8601String(),
                    'status' => 'settled', 'result' => $result];
                $prices[] = ['provider_match_id' => $id, 'market' => '1X2', 'prices' => ['H' => '2.00', 'D' => '3.00', 'A' => '4.00'],
                    'captured_at' => '2026-10-'.$date.'T11:00:00+00:00', 'pre_match_verified' => false];
            }
            // Synthetic unrelated quotes represent the price payload a season scan used to transfer.
            for ($i = 0; $i < 240; $i++) {
                $prices[] = [...$prices[0], 'provider_match_id' => 'vf:match:'.(1000000 + $i),
                    'provenance' => ['source_sha256' => str_repeat('a', 64), 'origin' => 'synthetic_test']];
            }
            $this->captures[(string) $season] = ['_id' => 'capture-'.$season, 'observed_at' => '2026-10-'.$date.'T11:00:00+00:00',
                'matches' => $matches, 'odds_snapshots' => $prices, 'standings' => ['status' => 'verified_final',
                    'observed_at' => '2026-10-'.$date.'T11:00:00+00:00',
                    'champion' => ['team_uid' => '1', 'team_name' => 'Synthetic champion']]];
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function database(bool $honorProjection): void
    {
        $this->reads = [];
        $this->bytes = 0;
        $state = Mockery::mock();
        $state->shouldReceive('findOne')->andReturn(['current_season_id' => '30', 'season_catalog' => []]);
        $links = Mockery::mock();
        $links->shouldReceive('find')->andReturnUsing(fn () => new ArrayIterator([
            ['from_season_id' => '10', 'to_season_id' => '20', 'observed_at' => '2026-10-23T09:00:00Z', 'continuity_status' => 'observed_rollover'],
            ['from_season_id' => '20', 'to_season_id' => '30', 'observed_at' => '2026-10-24T09:00:00Z', 'continuity_status' => 'observed_rollover'],
        ]));
        $empty = Mockery::mock();
        $empty->shouldReceive('find')->andReturnUsing(fn () => new ArrayIterator([]));
        $checkpoints = Mockery::mock();
        $checkpoints->shouldReceive('find')->andReturnUsing(fn () => new ArrayIterator(array_map(
            fn ($id) => ['_id' => 'virtual-euro:'.$id, 'capture_id' => 'capture-'.$id, 'odds_evidence_revision' => 0], ['10', '20', '30'])));
        $captures = Mockery::mock();
        $captures->shouldReceive('find')->andReturnUsing(function ($filter, $options) use ($honorProjection) {
            $projection = $options['projection'];
            $season = $filter['season_id'];
            $this->reads[$season] = $projection;
            $capture = $this->captures[$season];
            if ($honorProjection) {
                $capture = array_intersect_key($capture, ['_id' => true, ...$projection]);
            }
            $this->bytes += strlen(json_encode($capture, JSON_THROW_ON_ERROR));

            return new ArrayIterator([$capture]);
        });
        $database = (object) ['collector_states' => $state, 'provider_season_transitions' => $links,
            'provider_catalog_links' => $empty, 'market_blockages' => $empty, 'season_checkpoints' => $checkpoints, 'provider_captures' => $captures];
        $connection = Mockery::mock();
        $connection->shouldReceive('getDatabase')->andReturn($database);
        $manager = Mockery::mock();
        $manager->shouldReceive('connection')->with('mongodb')->andReturn($connection);
        DB::swap($manager);
    }

    public function test_preparation_omits_all_odds_but_keeps_identical_champions_and_qualification(): void
    {
        $seasons = [['competition' => 'virtual-euro', 'season_id' => '30', 'start_at' => '2026-10-24T10:00:00Z']];
        $this->database(false);
        $reference = app(ChampionMonitoring::class)->preparedRows($seasons);
        $fullBytes = $this->bytes;
        $this->database(true);
        $lean = app(ChampionMonitoring::class)->preparedRows($seasons);
        $this->assertSame($reference, $lean);
        $this->assertSame(5, $lean[0]['trigger_day']);
        $this->assertSame('Synthetic champion', $lean[0]['team']);
        $this->assertLessThan($fullBytes / 4, $this->bytes);
        foreach ($this->reads as $projection) {
            $this->assertArrayNotHasKey('odds_snapshots', $projection);
            $this->assertArrayHasKey('standings', $projection);
            $this->assertArrayHasKey('matches', $projection);
        }
    }

    public function test_standard_monitoring_keeps_current_replay_prices_and_identical_payouts(): void
    {
        $this->database(false);
        $reference = app(ChampionMonitoring::class)->forSeason('virtual-euro', '30');
        $fullBytes = $this->bytes;
        $this->database(true);
        $lean = app(ChampionMonitoring::class)->forSeason('virtual-euro', '30');
        $this->assertSame($reference, $lean);
        $this->assertSame('win', $lean[0]['simulation']['status']);
        $this->assertGreaterThan(0, $lean[0]['simulation']['gross_payout_minor']);
        $this->assertArrayHasKey('odds_snapshots', $this->reads['30']);
        $this->assertArrayNotHasKey('odds_snapshots', $this->reads['20']);
        $this->assertArrayNotHasKey('odds_snapshots', $this->reads['10']);
        $this->assertLessThan($fullBytes, $this->bytes);
    }

    public function test_unverified_previous_champion_still_blocks_without_loading_prices(): void
    {
        unset($this->captures['20']['standings']);
        $this->database(true);
        $rows = app(ChampionMonitoring::class)->forSeason('virtual-euro', '30');
        $this->assertSame('waiting_for_history', $rows[0]['status']);
        $this->assertNull($rows[0]['simulation']);
        foreach ($this->reads as $projection) {
            $this->assertArrayNotHasKey('odds_snapshots', $projection);
        }
    }
}
