<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\Cache;
use Throwable;

class SimulationCache
{
    public const RECENT_TTL = 604800;

    public const HISTORICAL_TTL = 86400;

    public function remember(string $key, string $revision, int $ttl, callable $compute): array
    {
        if (! config('kiotrad.simulation_cache_enabled')) {
            return $compute();
        }
        $key = 'simulation:'.hash('sha256', config('database.connections.mongodb.database').':'.$key.':'.$ttl);
        try {
            $cached = Cache::store('redis')->get($key);
            if (is_array($cached) && ($cached['revision'] ?? null) === $revision && is_array($cached['data'] ?? null)) {
                return $cached['data'];
            }
        } catch (Throwable) {
            // Redis is disposable. Source reads/calculations must still work.
        }
        $data = $compute();
        try {
            Cache::store('redis')->put($key, ['revision' => $revision, 'data' => $data], $ttl);
        } catch (Throwable) {
            // Never turn a cache write failure into a collection/simulation failure.
        }

        return $data;
    }
}
