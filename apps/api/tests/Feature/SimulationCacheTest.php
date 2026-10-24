<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\SimulationCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class SimulationCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recent_week_and_requested_historical_day_have_different_expirations(): void
    {
        config(['kiotrad.simulation_cache_enabled' => true, 'cache.stores.redis' => ['driver' => 'array']]);
        CarbonImmutable::setTestNow('2026-10-24T10:00:00Z');
        $cache = new SimulationCache;
        $calls = 0;
        $compute = function () use (&$calls) {
            return ['run' => ++$calls];
        };
        $this->assertSame(['run' => 1], $cache->remember('recent', 'v1', SimulationCache::RECENT_TTL, $compute));
        $this->assertSame(['run' => 2], $cache->remember('old', 'v1', SimulationCache::HISTORICAL_TTL, $compute));
        CarbonImmutable::setTestNow('2026-10-25T11:00:00Z');
        $this->assertSame(['run' => 1], $cache->remember('recent', 'v1', SimulationCache::RECENT_TTL, $compute));
        $this->assertSame(['run' => 3], $cache->remember('old', 'v1', SimulationCache::HISTORICAL_TTL, $compute));
        $this->assertSame(['run' => 4], $cache->remember('recent', 'source-repaired', SimulationCache::RECENT_TTL, $compute));
    }

    public function test_redis_failure_rebuilds_from_source_without_losing_the_result(): void
    {
        config(['kiotrad.simulation_cache_enabled' => true]);
        Cache::shouldReceive('store')->with('redis')->twice()->andThrow(new RuntimeException('Unavailable'));
        $this->assertSame(['net_minor' => 10000], (new SimulationCache)->remember('run', 'v1', 86400, fn () => ['net_minor' => 10000]));
    }
}
