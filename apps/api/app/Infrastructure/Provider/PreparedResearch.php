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
            $prepared = Cache::store('redis')->get($this->key());
            if (! is_array($prepared)) {
                return ['status' => 'warming', 'message' => 'Preparing reusable fixture and price evidence.'];
            }
            $revision = hash('sha256', json_encode([$prepared['revision'], $settings, ResearchScenario::VERSION,
                SharedCapitalReplay::VERSION, RunBudgetEstimate::VERSION]));
            $key = $this->key().':scenario:'.$revision;
            $cached = Cache::store('redis')->get($key);
            if (is_array($cached)) {
                return [...$cached, 'scenario_cache' => 'hit'];
            }
            $result = [...(new ResearchScenario)->calculate($prepared, $settings), 'data_revision' => $prepared['revision']];
            try {
                Cache::store('redis')->put($key, $result, SimulationCache::HISTORICAL_TTL);
            } catch (Throwable $exception) {
                report($exception);
            }

            return [...$result, 'scenario_cache' => 'miss'];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'message' => 'Scenario calculation unavailable; stored evidence is preserved.'];
        }
    }

    public function refresh(): string
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
            $previous = $cache->get($this->key());
            if (($previous['revision'] ?? null) === $revision) {
                return 'unchanged';
            }
            $data = app(SharedCapitalResearch::class)->prepare(now('UTC')->toIso8601String());
            if (! $lock->isOwnedByCurrentProcess()) {
                return 'lease_lost';
            }
            $cache->put($this->key(), [...$data, 'revision' => $revision], SimulationCache::RECENT_TTL);

            return 'prepared';
        } finally {
            SnapshotLease::release();
        }
    }
}
