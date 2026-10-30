<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\Cache;

/** Fixed slots bound cache growth; collisions evict, never share a result. */
final class ScenarioResultCache
{
    public const SLOTS = 16;

    public const MAX_ENTRY_BYTES = 4194304;

    private function key(string $scope, string $fingerprint): string
    {
        return $scope.':scenario-slot:'.(hexdec(substr(hash('sha256', $fingerprint), 0, 4)) % self::SLOTS);
    }

    public function get(string $scope, string $fingerprint): ?array
    {
        $cached = ResearchCachePayload::decode(Cache::store('redis')->get($this->key($scope, $fingerprint)));

        return ($cached['fingerprint'] ?? null) === $fingerprint && is_array($cached['data'] ?? null) ? $cached['data'] : null;
    }

    public function put(string $scope, string $fingerprint, array $data): void
    {
        $key = $this->key($scope, $fingerprint);
        $payload = ResearchCachePayload::encode(['fingerprint' => $fingerprint, 'data' => $data]);
        if (strlen($payload) > self::MAX_ENTRY_BYTES) {
            Cache::store('redis')->forget($key);

            return;
        }
        Cache::store('redis')->put($key, $payload, SimulationCache::HISTORICAL_TTL);
    }
}
