<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Cache pressure smoke requires APP_ENV=testing.');
}
$cache = Redis::connection('cache')->client();
$leases = Redis::connection('locks')->client();
if ($cache->info('server')['run_id'] === $leases->info('server')['run_id']) {
    throw new RuntimeException('Cache and locks must use separate Redis servers, not only separate databases.');
}
if (($leases->config('GET', 'maxmemory-policy')['maxmemory-policy'] ?? '') !== 'noeviction') {
    throw new RuntimeException('Lease server must not evict ownership keys.');
}
$original = $cache->config('GET', 'maxmemory')['maxmemory'];
$prefix = 'cache-pressure-smoke:'.bin2hex(random_bytes(8));
$lock = Cache::store('redis')->lock($prefix.':lock', 120);
$acquired = $lock->get();
if (! $acquired) {
    throw new RuntimeException('Could not acquire isolated smoke lock.');
}
try {
    $before = $cache->info('stats')['evicted_keys'];
    $cache->config('SET', 'maxmemory', (string) ($cache->info('memory')['used_memory'] + 2097152));
    for ($i = 0; $i < 64; $i++) {
        Cache::store('redis')->put($prefix.':'.$i, random_bytes(131072), 60);
    }
    if ($cache->info('stats')['evicted_keys'] <= $before || ! $lock->isOwnedByCurrentProcess()) {
        throw new RuntimeException('Cache pressure did not evict results or removed the independent lease.');
    }
    $competitor = Cache::store('redis')->lock($prefix.':lock', 120);
    if ($competitor->get()) {
        $competitor->release();
        throw new RuntimeException('A second worker acquired the active lease during cache eviction.');
    }
    if (! $lock->release() || ! $competitor->get()) {
        throw new RuntimeException('Independent lock could not release and reacquire after pressure.');
    }
    $competitor->release();
    echo "Cache pressure smoke passed: real eviction preserved exclusive worker ownership.\n";
} finally {
    $cache->config('SET', 'maxmemory', $original);
    $lock->release();
    for ($i = 0; $i < 64; $i++) {
        Cache::store('redis')->forget($prefix.':'.$i);
    }
}
