<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\RunReplay;
use App\Domain\Strategies\SimulationSeasonLinks;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChampionMonitoring
{
    public function __construct(private ChampionMonitor $monitor, private RunReplay $replay, private SimulationCache $cache) {}

    public function all(bool $eventOrder = true): array
    {
        $rows = [];
        foreach (config('kiotrad.competitions') as $competition) {
            try {
                $rows = [...$rows, ...$this->league($competition['id'], $eventOrder)];
            } catch (Throwable $exception) {
                report($exception);
                $rows[] = ['competition' => $competition['id'], 'status' => 'monitoring_unavailable', 'execution_enabled' => false];
            }
        }

        return $rows;
    }

    public function forSeason(string $competition, string $season, int $ttl = SimulationCache::HISTORICAL_TTL): array
    {
        return $this->league($competition, true, $season, $ttl);
    }

    private function league(string $competition, bool $eventOrder, ?string $requestedSeason = null, int $ttl = SimulationCache::RECENT_TTL): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $state = $db->collector_states->findOne(['_id' => $competition], $options);
        $currentId = $requestedSeason ?? $state['current_season_id'] ?? null;
        if (! $currentId) {
            return [['competition' => $competition, 'status' => 'waiting_for_collection', 'execution_enabled' => false]];
        }
        $asOf = now('UTC')->toIso8601String();
        $links = iterator_to_array($db->provider_season_transitions->find(['competition' => $competition], $options), false);
        if ($eventOrder) {
            $archiveLinks = iterator_to_array($db->provider_catalog_links->find(['competition' => $competition], $options), false);
            $links = [...$links, ...$archiveLinks];
            $links = (new SimulationSeasonLinks)->merge($links, $state['season_catalog'] ?? [], $state['collection_observed_at'] ?? $asOf);
        }
        $ids = [$currentId];
        for ($i = 0; $i < 2; $i++) {
            $parents = array_values(array_filter($links, fn ($l) => $l['to_season_id'] === $ids[$i]));
            if (count($parents) !== 1) {
                break;
            }
            $ids[] = $parents[0]['from_season_id'];
        }
        $blocks = iterator_to_array($db->market_blockages->find(['competition' => $competition, 'season_id' => $currentId,
            'classification' => 'operator_confirmed'], [...$options, 'projection' => ['matchday' => 1], 'sort' => ['_id' => 1]]), false);
        $blockedDays = array_values(array_unique(array_column($blocks, 'matchday')));
        $compute = fn () => $this->evaluateSeasons($db, $options, $competition, $currentId, $ids, $links, $asOf, $eventOrder, $blockedDays);
        if (! $eventOrder) {
            return $compute();
        }
        $checkpoints = iterator_to_array($db->season_checkpoints->find(['_id' => ['$in' => array_map(fn ($id) => $competition.':'.$id, $ids)]],
            [...$options, 'projection' => ['capture_id' => 1], 'sort' => ['_id' => 1]]), false);
        $relevantLinks = array_values(array_filter($links, fn ($l) => in_array($l['to_season_id'], $ids, true)));
        $revision = hash('sha256', json_encode([$checkpoints, $relevantLinks, $blocks, ChampionMonitor::SIMULATION_VERSION, RunReplay::EVENT_VERSION,
            config('kiotrad.simulation_capital_minor'), $currentId === ($state['current_season_id'] ?? null) ? intdiv(now('UTC')->timestamp, 60) : null], JSON_THROW_ON_ERROR));

        return $this->cache->remember($competition.':'.$currentId, $revision, $ttl, $compute);
    }

    private function evaluateSeasons($db, array $options, string $competition, string $currentId, array $ids, array $links, string $asOf, bool $eventOrder, array $blockedDays): array
    {
        $seasons = [];
        $currentCaptures = [];
        foreach (array_reverse($ids) as $id) {
            $season = ['season_id' => $id, 'matches' => [], 'champion' => null];
            $seen = [];
            $captures = $db->provider_captures->find(['competition' => $competition, 'season_id' => $id, 'observed_at' => ['$lte' => $asOf]],
                [...$options, 'sort' => ['observed_at' => 1]]);
            foreach ($captures as $capture) {
                $trackedTeam = $seasons[$ids[1] ?? '']['champion']['team_uid'] ?? null;
                if ($id === $currentId && $trackedTeam !== null) {
                    $runMatches = array_values(array_filter($capture['matches'], fn ($m) => in_array($trackedTeam,
                        [$m['home_team_uid'], $m['away_team_uid']], true)));
                    $matchIds = array_column($runMatches, 'provider_match_id');
                    $currentCaptures[] = ['_id' => $capture['_id'], 'observed_at' => $capture['observed_at'],
                        'matches' => $runMatches, 'odds_snapshots' => array_values(array_filter($capture['odds_snapshots'] ?? [],
                            fn ($p) => in_array($p['provider_match_id'], $matchIds, true)))];
                }
                foreach ($capture['matches'] as $match) {
                    if ($match['status'] === 'settled') {
                        $seen[$match['provider_match_id']] ??= $capture['observed_at'];
                        $match['result_observed_at'] = $seen[$match['provider_match_id']];
                    }
                    $season['matches'][$match['provider_match_id']] = $match;
                }
                if (($capture['standings']['status'] ?? null) === 'verified_final' && $season['champion'] === null) {
                    $season['champion'] = [...$capture['standings']['champion'], 'confirmed_at' => $capture['standings']['observed_at']];
                }
            }
            $seasons[$id] = $season;
        }

        $rows = array_map(fn ($strategy) => ['competition' => $competition, 'blocked_matchdays' => $blockedDays, ...$this->monitor->evaluate($strategy,
            $seasons[$currentId], $seasons[$ids[1] ?? ''] ?? null, $seasons[$ids[2] ?? ''] ?? null, $links, $asOf, $eventOrder)], ['champion-next-win', 'champion-next-season']);

        return array_map(fn ($row) => [...$row, 'simulation' => $this->replay->calculate($row, $currentCaptures,
            config('kiotrad.simulation_capital_minor'), $asOf, $eventOrder)], $rows);
    }
}
