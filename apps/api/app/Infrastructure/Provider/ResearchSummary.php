<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\RunReplay;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResearchSummary
{
    public function __construct(private SimulationHistory $history, private SimulationCache $cache) {}

    public function overview(): array
    {
        try {
            return $this->build();
        } catch (Throwable) {
            return ['status' => 'unavailable', 'mode' => 'independent_event_order_research', 'runs' => []];
        }
    }

    public function build(): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'sort' => ['_id' => 1]];
        $sources = iterator_to_array($db->season_checkpoints->find([], [...$options, 'projection' => ['capture_id' => 1, 'odds_evidence_revision' => 1]]), false);
        $links = iterator_to_array($db->provider_catalog_links->find([], $options), false);
        $transitions = iterator_to_array($db->provider_season_transitions->find([], $options), false);
        $catalogs = iterator_to_array($db->collector_states->find([], [...$options, 'projection' => ['season_catalog' => 1]]), false);
        $blocks = iterator_to_array($db->market_blockages->find(['classification' => 'operator_confirmed'], [...$options, 'projection' => ['matchday' => 1]]), false);
        $revision = hash('sha256', json_encode([$sources, $links, $transitions, $catalogs, $blocks,
            ChampionMonitor::SIMULATION_VERSION, RunReplay::EVENT_VERSION, config('kiotrad.simulation_capital_minor')], JSON_THROW_ON_ERROR));

        return $this->cache->remember('research:all-seasons-v2', $revision, SimulationCache::RECENT_TTL, function () {
            $seasons = $this->history->seasons('all');
            $starts = array_filter(array_column($seasons, 'start_at'));
            $from = $starts === [] ? null : min($starts);
            $to = now('UTC')->toIso8601String();
            $rows = $this->history->rows($seasons);
            $runs = [];
            foreach ($rows as $row) {
                $simulation = $row['simulation'] ?? null;
                if (! $simulation) {
                    continue;
                }
                $last = empty($simulation['attempts']) ? null : $simulation['attempts'][array_key_last($simulation['attempts'])];
                $runs[] = ['competition' => $row['competition'], 'season_id' => $row['season_id'], 'strategy_id' => $row['strategy_id'],
                    'team' => $row['team'], 'status' => $simulation['status'], 'net_minor' => $simulation['net_minor'],
                    'total_staked_minor' => $simulation['total_staked_minor'], 'gross_payout_minor' => $simulation['gross_payout_minor'],
                    'event_at' => $last['scheduled_start_at'] ?? null,
                    'started_at' => $simulation['attempts'][0]['scheduled_start_at'] ?? null,
                    'attempts' => count($simulation['attempts'])];
            }

            return ['status' => 'available', 'mode' => 'independent_event_order_research', 'from' => $from, 'to' => $to,
                'generated_at' => $to, 'scope' => 'all_stored_seasons', 'seasons_assessed' => count($seasons), 'starting_capital_per_run_minor' => config('kiotrad.simulation_capital_minor'), 'runs' => $runs];
        });
    }
}
