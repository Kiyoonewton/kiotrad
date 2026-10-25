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
        $sources = iterator_to_array($db->season_checkpoints->find([], [...$options, 'projection' => ['capture_id' => 1]]), false);
        $links = iterator_to_array($db->provider_catalog_links->find([], $options), false);
        $transitions = iterator_to_array($db->provider_season_transitions->find([], $options), false);
        $catalogs = iterator_to_array($db->collector_states->find([], [...$options, 'projection' => ['season_catalog' => 1]]), false);
        $blocks = iterator_to_array($db->market_blockages->find(['classification' => 'operator_confirmed'], [...$options, 'projection' => ['matchday' => 1]]), false);
        $revision = hash('sha256', json_encode([$sources, $links, $transitions, $catalogs, $blocks,
            ChampionMonitor::SIMULATION_VERSION, RunReplay::EVENT_VERSION, config('kiotrad.simulation_capital_minor'), intdiv(now('UTC')->timestamp, 60)], JSON_THROW_ON_ERROR));

        return $this->cache->remember('research:recent-week', $revision, SimulationCache::RECENT_TTL, function () {
            $from = now('UTC')->subDays(7)->toIso8601String();
            $to = now('UTC')->toIso8601String();
            $rows = $this->history->rows($this->history->seasons('all', $from, $to));
            $runs = [];
            foreach ($rows as $row) {
                $simulation = $row['simulation'] ?? null;
                if (! $simulation) {
                    continue;
                }
                $last = empty($simulation['attempts']) ? null : $simulation['attempts'][array_key_last($simulation['attempts'])];
                if ($simulation['net_minor'] !== null && $last !== null
                    && ($last['scheduled_start_at'] < $from || $last['scheduled_start_at'] > $to)) {
                    continue;
                }
                $runs[] = ['competition' => $row['competition'], 'season_id' => $row['season_id'], 'strategy_id' => $row['strategy_id'],
                    'team' => $row['team'], 'status' => $simulation['status'], 'net_minor' => $simulation['net_minor'],
                    'total_staked_minor' => $simulation['total_staked_minor'], 'gross_payout_minor' => $simulation['gross_payout_minor'],
                    'event_at' => $last['scheduled_start_at'] ?? null];
            }

            return ['status' => 'available', 'mode' => 'independent_event_order_research', 'from' => $from, 'to' => $to,
                'generated_at' => $to, 'starting_capital_per_run_minor' => config('kiotrad.simulation_capital_minor'), 'runs' => $runs];
        });
    }
}
