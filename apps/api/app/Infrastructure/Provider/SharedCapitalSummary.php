<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\RunBudgetEstimate;
use App\Domain\Research\SharedCapitalReplay;
use App\Domain\Strategies\ChampionMonitor;
use Illuminate\Support\Facades\DB;
use Throwable;

class SharedCapitalSummary
{
    public function __construct(private SharedCapitalResearch $research, private SimulationCache $cache) {}

    public function overview(): array
    {
        try {
            $db = DB::connection('mongodb')->getDatabase();
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'sort' => ['_id' => 1]];
            $evidence = [];
            foreach (['season_checkpoints', 'provider_catalog_links', 'provider_season_transitions', 'market_blockages'] as $name) {
                $evidence[$name] = iterator_to_array($db->selectCollection($name)->find([], $options), false);
            }
            $capital = config('kiotrad.simulation_capital_minor');
            $revision = hash('sha256', json_encode([$evidence, $capital, ChampionMonitor::SIMULATION_VERSION, SharedCapitalReplay::VERSION,
                RunBudgetEstimate::VERSION, now('UTC')->format('Y-m-d H:i')], JSON_THROW_ON_ERROR));

            return $this->cache->remember('dashboard:shared-capital-v1', $revision, SimulationCache::RECENT_TTL,
                fn () => ['status' => 'available', ...$this->research->build($capital, now('UTC')->toIso8601String())]);
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'mode' => 'shared_capital_simulation', 'runs' => [],
                'reason' => 'shared_capital_calculation_unavailable'];
        }
    }
}
