<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\RunReplay;
use App\Domain\Strategies\SimulationSeasonLinks;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChampionMonitoring
{
    private ?array $batch = null;

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

    public function forSeasons(array $seasons): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $groups = [];
        foreach ($seasons as $season) {
            $groups[$season['competition']][] = $season;
        }
        $rows = [];
        try {
            foreach ($groups as $competition => $group) {
                $state = $db->collector_states->findOne(['_id' => $competition], $options);
                $links = iterator_to_array($db->provider_season_transitions->find(['competition' => $competition], $options), false);
                $archive = iterator_to_array($db->provider_catalog_links->find(['competition' => $competition], $options), false);
                $links = (new SimulationSeasonLinks)->merge([...$links, ...$archive], $state['season_catalog'] ?? [], $state['collection_observed_at'] ?? now('UTC')->toIso8601String());
                $checkpoints = iterator_to_array($db->season_checkpoints->find(['_id' => ['$regex' => '^'.preg_quote($competition, '/').':']],
                    [...$options, 'projection' => ['capture_id' => 1], 'sort' => ['_id' => 1]]), false);
                $blocks = iterator_to_array($db->market_blockages->find(['competition' => $competition, 'classification' => 'operator_confirmed'],
                    [...$options, 'projection' => ['season_id' => 1, 'matchday' => 1], 'sort' => ['_id' => 1]]), false);
                // Bound capture memory; each chunk includes its two linked predecessors.
                foreach (array_chunk($group, 10) as $chunk) {
                    $ids = [];
                    foreach ($chunk as $season) {
                        $id = (string) $season['season_id'];
                        $ids[] = $id;
                        for ($i = 0; $i < 2; $i++) {
                            $parents = array_values(array_filter($links, fn ($l) => $l['to_season_id'] === $id));
                            if (count($parents) !== 1) {
                                break;
                            }
                            $id = $parents[0]['from_season_id'];
                            $ids[] = $id;
                        }
                    }
                    $captures = [];
                    foreach ($db->provider_captures->find(['competition' => $competition, 'season_id' => ['$in' => array_values(array_unique($ids))],
                        'observed_at' => ['$lte' => now('UTC')->toIso8601String()]], [...$options, 'sort' => ['observed_at' => 1],
                            'projection' => ['season_id' => 1, 'observed_at' => 1, 'matches' => 1, 'standings' => 1, 'odds_snapshots' => 1]]) as $capture) {
                        $captures[(string) $capture['season_id']][] = $capture;
                    }
                    $this->batch = compact('competition', 'state', 'links', 'checkpoints', 'blocks', 'captures');
                    foreach ($chunk as $season) {
                        $ttl = ($season['start_at'] ?? '') >= now('UTC')->subDays(7)->toIso8601String()
                            ? SimulationCache::RECENT_TTL : SimulationCache::HISTORICAL_TTL;
                        $rows = [...$rows, ...$this->forSeason($competition, (string) $season['season_id'], $ttl)];
                    }
                    $this->batch = null;
                }
            }
        } finally {
            $this->batch = null;
        }

        return $rows;
    }

    private function league(string $competition, bool $eventOrder, ?string $requestedSeason = null, int $ttl = SimulationCache::RECENT_TTL): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $state = $this->batch !== null ? $this->batch['state'] : $db->collector_states->findOne(['_id' => $competition], $options);
        $currentId = $requestedSeason ?? $state['current_season_id'] ?? null;
        if (! $currentId) {
            return [['competition' => $competition, 'status' => 'waiting_for_collection', 'execution_enabled' => false]];
        }
        $asOf = now('UTC')->toIso8601String();
        $links = $this->batch !== null ? $this->batch['links'] : iterator_to_array($db->provider_season_transitions->find(['competition' => $competition], $options), false);
        if ($eventOrder && $this->batch === null) {
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
        $blocks = $this->batch !== null ? array_values(array_filter($this->batch['blocks'], fn ($b) => (string) $b['season_id'] === $currentId)) : iterator_to_array($db->market_blockages->find(['competition' => $competition, 'season_id' => $currentId,
            'classification' => 'operator_confirmed'], [...$options, 'projection' => ['matchday' => 1], 'sort' => ['_id' => 1]]), false);
        $blocks = array_map(fn ($b) => array_intersect_key($b, array_flip(['_id', 'matchday'])), $blocks);
        $blockedDays = array_values(array_unique(array_column($blocks, 'matchday')));
        $compute = fn () => $this->evaluateSeasons($db, $options, $competition, $currentId, $ids, $links, $asOf, $eventOrder, $blockedDays);
        if (! $eventOrder) {
            return $compute();
        }
        $checkpoints = $this->batch !== null ? array_values(array_filter($this->batch['checkpoints'], fn ($c) => in_array($c['_id'], array_map(fn ($id) => $competition.':'.$id, $ids), true))) : iterator_to_array($db->season_checkpoints->find(['_id' => ['$in' => array_map(fn ($id) => $competition.':'.$id, $ids)]],
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
            $captures = $this->batch !== null ? ($this->batch['captures'][(string) $id] ?? []) : $db->provider_captures->find(['competition' => $competition, 'season_id' => $id, 'observed_at' => ['$lte' => $asOf]],
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
