<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\ResearchScenario;
use App\Domain\Research\RunBudgetEstimate;
use App\Domain\Research\SharedCapitalReplay;
use App\Domain\Strategies\ChampionMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Background source preparation; HTTP scenario requests never rebuild MongoDB/raw evidence. */
class PreparedResearch
{
    public const VERSION = 'all-first-wins-ten-fixtures-1.0.0';

    private function key(): string
    {
        return 'research-prepared:'.hash('sha256', json_encode([config('database.connections.mongodb.database'),
            config('database.connections.mongodb.dsn'), self::VERSION, ChampionMonitor::SIMULATION_VERSION]));
    }

    public function scenario(array $settings): array
    {
        try {
            try {
                $prepared = ResearchCachePayload::decode(Cache::store('redis')->get($this->key()));
            } catch (Throwable) {
                $prepared = null;
            }
            if (! is_array($prepared)) {
                $prepared = (new DerivedViewBackup)->read($this->key());
            }
            if (! is_array($prepared)) {
                $state = $this->state();

                return ['status' => ($state['status'] ?? '') === 'failed' ? 'unavailable' : 'warming',
                    'message' => ($state['status'] ?? '') === 'failed'
                        ? 'Evidence preparation failed; the worker will retry. Stored source data is preserved.'
                        : 'Preparing reusable fixture and price evidence.', 'preparation' => $state];
            }
            $revision = hash('sha256', json_encode([$prepared['revision'], $settings, ResearchScenario::VERSION,
                SharedCapitalReplay::VERSION, RunBudgetEstimate::VERSION]));
            $scenarioCache = new ScenarioResultCache;
            try {
                $cached = $scenarioCache->get($this->key(), $revision);
            } catch (Throwable) {
                $cached = null;
            }
            if (is_array($cached)) {
                return [...$cached, 'scenario_cache' => 'hit', 'preparation' => $this->state()];
            }
            $result = [...(new ResearchScenario)->calculate($prepared, $settings), 'data_revision' => $prepared['revision']];
            try {
                $scenarioCache->put($this->key(), $revision, $result);
            } catch (Throwable $exception) {
                report($exception);
            }

            return [...$result, 'scenario_cache' => 'miss', 'preparation' => $this->state()];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'message' => 'Scenario calculation unavailable; stored evidence is preserved.'];
        }
    }

    private function state(): ?array
    {
        try {
            $state = Cache::store('redis')->get($this->key().':state');

            return is_array($state) ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function refresh(?callable $progress = null): string
    {
        $cache = Cache::store('redis');
        $lock = $cache->lock($this->key().':lock', 3600);
        if (! $lock->get()) {
            return 'busy';
        }
        SnapshotLease::protect($lock);
        try {
            $db = DB::connection('mongodb')->getDatabase();
            $evidence = [];
            foreach (['season_checkpoints', 'provider_catalog_links', 'provider_season_transitions', 'market_blockages'] as $name) {
                $evidence[$name] = iterator_to_array($db->selectCollection($name)->find([], [
                    'sort' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                ]), false);
            }
            $revision = hash('sha256', json_encode([$evidence, self::VERSION, ChampionMonitor::SIMULATION_VERSION]));
            $previous = ResearchCachePayload::decode($cache->get($this->key())) ?? (new DerivedViewBackup)->read($this->key());
            if (($previous['revision'] ?? null) === $revision) {
                if ((new DerivedViewBackup)->read($this->key()) === null) {
                    (new DerivedViewBackup)->write($this->key(), $previous);
                }
                $cache->put($this->key(), ResearchCachePayload::encode($previous), SimulationCache::RECENT_TTL);
                $cache->put($this->key().':state', ['status' => 'ready', 'checked_at' => now('UTC')->toIso8601String()], SimulationCache::RECENT_TTL);

                return 'unchanged';
            }
            $cache->put($this->key().':state', ['status' => 'building', 'started_at' => now('UTC')->toIso8601String()], SimulationCache::RECENT_TTL);
            $data = app(SharedCapitalResearch::class)->prepare(now('UTC')->toIso8601String(),
                function ($phase, $done, $total) use ($cache, $progress): void {
                    $cache->put($this->key().':state', ['status' => 'building', 'phase' => $phase,
                        'done' => $done, 'total' => $total, 'updated_at' => now('UTC')->toIso8601String()], SimulationCache::RECENT_TTL);
                    $progress && $progress($phase, $done, $total);
                });
            if (! $lock->isOwnedByCurrentProcess()) {
                return 'lease_lost';
            }
            $data = [...$data, 'revision' => $revision];
            (new DerivedViewBackup)->write($this->key(), $data);
            $cache->put($this->key(), ResearchCachePayload::encode($data), SimulationCache::RECENT_TTL);
            $cache->put($this->key().':state', ['status' => 'ready', 'completed_at' => now('UTC')->toIso8601String()], SimulationCache::RECENT_TTL);

            return 'prepared';
        } catch (Throwable $exception) {
            $cache->put($this->key().':state', ['status' => 'failed', 'failed_at' => now('UTC')->toIso8601String()], SimulationCache::RECENT_TTL);
            throw $exception;
        } finally {
            SnapshotLease::release();
        }
    }
}
