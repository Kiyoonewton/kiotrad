<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ChampionMonitoring;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchSimulationTest extends TestCase
{
    public function test_history_batches_remote_reads_and_releases_context_before_live_reads(): void
    {
        config(['kiotrad.simulation_cache_enabled' => false]);
        $db = new \stdClass;
        foreach (['collector_states', 'provider_season_transitions', 'provider_catalog_links', 'season_checkpoints', 'market_blockages', 'provider_captures'] as $name) {
            $db->$name = new BatchReadCollection;
        }
        DB::shouldReceive('connection->getDatabase')->andReturn($db);
        $seasons = array_map(fn ($i) => ['competition' => 'virtual-football', 'season_id' => (string) $i], range(1, 21));
        $monitor = app(ChampionMonitoring::class);
        $rows = $monitor->forSeasons($seasons);
        $this->assertCount(42, $rows);
        $this->assertSame(1, $db->collector_states->reads);
        $this->assertSame(1, $db->provider_catalog_links->reads);
        $this->assertSame(1, $db->season_checkpoints->reads);
        $this->assertSame(3, $db->provider_captures->reads);
        $this->assertSame('1', $rows[0]['season_id']);
        $monitor->forSeason('virtual-football', '22');
        $this->assertSame(2, $db->collector_states->reads, 'Batch metadata leaked into a later live read.');
        $this->assertSame(4, $db->provider_captures->reads);
    }
}

class BatchReadCollection
{
    public int $reads = 0;

    public function findOne(array $filter, array $options): ?array
    {
        $this->reads++;

        return null;
    }

    public function find(array $filter, array $options): ArrayIterator
    {
        $this->reads++;

        return new ArrayIterator([]);
    }
}
