<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ResearchCachePayload;
use App\Infrastructure\Provider\ScenarioResultCache;
use ReflectionMethod;
use Tests\TestCase;

class ResearchCacheBoundsTest extends TestCase
{
    public function test_compression_preserves_exact_array_values_and_supports_old_arrays(): void
    {
        $data = ['minor' => 43458978, 'negative' => -10000, 'empty' => [], 'missing' => null,
            'nested' => array_fill(0, 1000, ['team' => 'Borussia M’gladbach', 'odds' => 1.35])];
        $packed = ResearchCachePayload::encode($data);
        $this->assertSame($data, ResearchCachePayload::decode($packed));
        $this->assertLessThan(strlen(serialize($data)) / 4, strlen($packed));
        $this->assertSame($data, ResearchCachePayload::decode($data));
        $this->assertNull(ResearchCachePayload::decode('kiotrad-gzip-v1:corrupt'));
    }

    public function test_oversized_scenario_is_not_retained(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        $cache = new ScenarioResultCache;
        $cache->put('scope', 'large', ['data' => random_bytes(ScenarioResultCache::MAX_ENTRY_BYTES + 1024)]);
        $this->assertNull($cache->get('scope', 'large'));
    }

    public function test_fixed_slots_replace_collisions_without_reusing_wrong_settings_or_revision(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        $cache = new ScenarioResultCache;
        $method = new ReflectionMethod($cache, 'key');
        $groups = [];
        for ($i = 0; $i < 100; $i++) {
            $groups[$method->invoke($cache, 'scope', (string) $i)][] = (string) $i;
        }
        $this->assertLessThanOrEqual(ScenarioResultCache::SLOTS, count($groups));
        $pair = array_values(array_filter($groups, fn ($items) => count($items) > 1))[0];
        $cache->put('scope', $pair[0], ['capital' => 50000]);
        $this->assertSame(['capital' => 50000], $cache->get('scope', $pair[0]));
        $cache->put('scope', $pair[1], ['capital' => 100000]);
        $this->assertNull($cache->get('scope', $pair[0]));
        $this->assertNull($cache->get('another-snapshot', $pair[1]));
        $this->assertSame(['capital' => 100000], $cache->get('scope', $pair[1]));
        $this->travel(2)->days();
        $this->assertNull($cache->get('scope', $pair[1]));
    }
}
