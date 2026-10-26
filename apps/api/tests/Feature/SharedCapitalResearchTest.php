<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\FixtureBaselineStore;
use App\Infrastructure\Provider\SharedCapitalResearch;
use App\Infrastructure\Provider\SimulationHistory;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SharedCapitalResearchTest extends TestCase
{
    public function test_stored_qualified_runs_use_prior_minima_and_labeled_modeled_settlement(): void
    {
        config(['provider.competitions' => ['virtual-football' => []], 'kiotrad.simulation_cache_enabled' => false]);
        $matches = [];
        foreach (range(5, 8) as $day) {
            $matches[] = ['provider_match_id' => (string) $day, 'matchday' => $day,
                'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Synthetic', 'away_team' => 'Opponent',
                'scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', ($day - 5) * 4),
                'status' => 'settled', 'result' => 'H'];
        }
        $collection = Mockery::mock();
        $collection->shouldReceive('find')->once()->andReturn(new ArrayIterator([
            ['_id' => 'capture', 'observed_at' => '2026-10-20T10:14:00Z', 'matches' => $matches, 'odds_snapshots' => array_map(fn ($m) => [
                'provider_match_id' => $m['provider_match_id'], 'prices' => ['H' => '2.0'],
                'captured_at' => '2026-10-20T10:14:00Z', 'pre_match_verified' => false,
            ], $matches)],
        ]));
        $checkpoint = Mockery::mock();
        $checkpoint->shouldReceive('findOne')->andReturn(['capture_id' => 'capture']);
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['provider_captures' => $collection, 'season_checkpoints' => $checkpoint]);
        $history = $this->mock(SimulationHistory::class);
        $history->shouldReceive('seasons')->once()->andReturn([['season_id' => '100']]);
        $history->shouldReceive('rows')->once()->andReturn([
            ['competition' => 'virtual-football', 'season_id' => '100', 'strategy_id' => 'champion-next-win',
                'trigger_day' => 4, 'team_uid' => '1', 'team' => 'Synthetic', 'evidence_ready_at' => '2026-10-20T09:58:00Z'],
        ]);
        $baseline = $this->mock(FixtureBaselineStore::class);
        $baseline->shouldReceive('refresh')->once()->with('virtual-football')->andReturn([]);
        $baseline->shouldReceive('before')->times(4)->with('virtual-football', Mockery::any(), 'H', '2026-10-20T10:00:00Z')
            ->andReturn(['odds_scaled' => 20000, 'season_id' => '99']);
        $result = app(SharedCapitalResearch::class)->build(5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('win', $result['runs'][0]['status']);
        $this->assertSame('2026-10-20T10:03:21.000000Z', $result['runs'][0]['released_at']);
        $this->assertSame('2026-10-20T10:14:00.000000Z', $result['runs'][0]['result_observed_at']);
        $this->assertFalse($result['runs'][0]['fixtures'][0]['price_timing_verified']);
        $this->assertSame('99', $result['runs'][0]['fixtures'][0]['historical_minimum']['season_id']);
        $this->assertSame(5096100, $result['balance_minor']);
    }
}
