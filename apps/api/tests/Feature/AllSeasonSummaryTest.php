<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ResearchSummary;
use App\Infrastructure\Provider\SimulationHistory;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AllSeasonSummaryTest extends TestCase
{
    public function test_summary_includes_old_qualifying_seasons_and_reuses_unchanged_cached_results(): void
    {
        config(['kiotrad.simulation_cache_enabled' => true, 'cache.stores.redis' => ['driver' => 'array']]);
        $collection = Mockery::mock();
        $collection->shouldReceive('find')->andReturnUsing(fn () => new ArrayIterator([]));
        $db = (object) array_fill_keys(['season_checkpoints', 'provider_catalog_links', 'provider_season_transitions', 'collector_states', 'market_blockages'], $collection);
        DB::shouldReceive('connection->getDatabase')->andReturn($db);
        $seasons = [['competition' => 'virtual-football', 'season_id' => '100', 'start_at' => '2020-01-01T00:00:00Z']];
        $history = $this->mock(SimulationHistory::class);
        $history->shouldReceive('seasons')->once()->with('all')->andReturn($seasons);
        $history->shouldReceive('rows')->once()->with($seasons)->andReturn([[
            'competition' => 'virtual-football', 'season_id' => '100', 'strategy_id' => 'champion-next-win', 'team' => 'Synthetic',
            'simulation' => ['status' => 'win', 'net_minor' => 10000, 'total_staked_minor' => 20000, 'gross_payout_minor' => 30000,
                'attempts' => [['scheduled_start_at' => '2020-01-01T01:00:00Z']]],
        ]]);
        $summary = app(ResearchSummary::class);
        $first = $summary->build();
        $this->assertSame('all_stored_seasons', $first['scope']);
        $this->assertSame(1, $first['seasons_assessed']);
        $this->assertSame(10000, $first['runs'][0]['net_minor']);
        $this->assertSame($first, $summary->build());
    }
}
