<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\DashboardSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DashboardSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cache.stores.redis' => ['driver' => 'array'], 'kiotrad.dashboard_async' => true]);
    }

    public function test_cold_http_request_never_connects_to_mongo_or_runs_replay(): void
    {
        DB::shouldReceive('connection')->never();
        $this->getJson('/api/v1/overview')->assertOk()->assertJsonPath('dashboard_cache.status', 'warming')
            ->assertJsonPath('bankroll.balance_minor', null);
    }

    public function test_background_refresh_retains_previous_good_view_after_source_failure(): void
    {
        $builder = Mockery::mock(DashboardSnapshot::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $data = ['schema_version' => '1', 'generated_at' => now('UTC')->toIso8601String(), 'competitions' => [],
            'strategy_monitoring' => [], 'research_simulation' => ['status' => 'available', 'runs' => []]];
        $builder->shouldReceive('build')->once()->andReturn($data);
        $snapshot = $builder;
        $this->assertSame('refreshed', $snapshot->refresh());
        $this->assertSame('ready', $snapshot->read()['dashboard_cache']['status']);
        $this->travel(2)->minutes();
        $this->assertSame('stale', $snapshot->read()['dashboard_cache']['status']);
        $builder->shouldReceive('build')->once()->andReturn([...$data, 'research_simulation' => ['status' => 'unavailable']]);
        $this->assertSame('source_unavailable', $snapshot->refresh());
        $this->assertSame('available', $snapshot->read()['research_simulation']['status']);
        Cache::store('redis')->flush();
        $this->assertSame('stale', $snapshot->read()['dashboard_cache']['status']);
        $this->assertSame('last_good_backup', $snapshot->read()['dashboard_cache']['source']);
    }
}
