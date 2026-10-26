<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\SharedCapitalResearch;
use App\Infrastructure\Provider\SharedCapitalSummary;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SharedCapitalSummaryTest extends TestCase
{
    public function test_dashboard_builds_shared_replay_with_configured_capital(): void
    {
        config(['kiotrad.simulation_capital_minor' => 5000000, 'kiotrad.simulation_cache_enabled' => false]);
        $collection = Mockery::mock();
        $collection->shouldReceive('find')->times(4)->andReturnUsing(fn () => new ArrayIterator([]));
        $db = Mockery::mock();
        $db->shouldReceive('selectCollection')->times(4)->andReturn($collection);
        DB::shouldReceive('connection->getDatabase')->once()->andReturn($db);
        $this->mock(SharedCapitalResearch::class)->shouldReceive('build')->once()->with(5000000, Mockery::type('string'))
            ->andReturn(['mode' => 'shared_capital_simulation', 'balance_minor' => 5000000, 'runs' => []]);
        $view = app(SharedCapitalSummary::class)->overview();
        $this->assertSame('available', $view['status']);
        $this->assertSame('shared_capital_simulation', $view['mode']);
        $this->assertSame(5000000, $view['balance_minor']);
    }
}
