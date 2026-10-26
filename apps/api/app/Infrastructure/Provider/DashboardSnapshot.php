<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\StrategyCatalog;
use App\Http\Controllers\OverviewController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DashboardSnapshot
{
    private function key(): string
    {
        return 'dashboard:'.hash('sha256', json_encode([config('database.connections.mongodb.database'),
            config('database.connections.mongodb.dsn'), config('kiotrad.simulation_capital_minor'), ChampionMonitor::SIMULATION_VERSION]));
    }

    public function read(): array
    {
        try {
            $data = Cache::store('redis')->get($this->key());
            if (is_array($data) && isset($data['generated_at'])) {
                $age = CarbonImmutable::parse($data['generated_at'])->diffInSeconds(now('UTC'), false);

                return [...$data, 'dashboard_cache' => ['status' => $age > 90 ? 'stale' : 'ready', 'generated_at' => $data['generated_at']]];
            }

            return $this->empty('warming');
        } catch (Throwable) {
            return $this->empty('unavailable');
        }
    }

    public function refresh(): string
    {
        $lock = Cache::store('redis')->lock($this->key().':lock', 3600);
        if (! $lock->get()) {
            return 'busy';
        }
        SnapshotLease::protect($lock);
        try {
            $data = $this->build();
            if (($data['research_simulation']['status'] ?? '') !== 'available'
                || array_filter($data['competitions'] ?? [], fn ($c) => ($c['feed_status'] ?? '') === 'unavailable')
                || array_filter($data['strategy_monitoring'] ?? [], fn ($r) => ($r['status'] ?? '') === 'monitoring_unavailable')) {
                return 'source_unavailable';
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                return 'lease_lost';
            }
            Cache::store('redis')->put($this->key(), $data, SimulationCache::RECENT_TTL);

            return 'refreshed';
        } finally {
            SnapshotLease::release();
        }
    }

    protected function build(): array
    {
        return app()->call([app(OverviewController::class), 'build'])->getData(true);
    }

    private function empty(string $status): array
    {
        return ['schema_version' => '1', 'mode' => 'foundation', 'currency' => 'NGN', 'display_timezone' => 'Africa/Lagos',
            'dashboard_cache' => ['status' => $status, 'generated_at' => null],
            'competitions' => array_map(fn ($c) => [...$c, 'feed_status' => 'not_connected'], config('kiotrad.competitions')),
            'strategies' => app(StrategyCatalog::class)->all(), 'strategy_monitoring' => [],
            'bankroll' => ['status' => 'unavailable', 'balance_minor' => null],
            'research_simulation' => ['status' => 'refreshing', 'runs' => []]];
    }
}
