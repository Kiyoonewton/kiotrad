<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\RunReplay;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChampionMonitoring
{
    public function __construct(private ChampionMonitor $monitor, private RunReplay $replay) {}

    public function all(): array
    {
        $rows = [];
        foreach (config('kiotrad.competitions') as $competition) {
            try {
                $rows = [...$rows, ...$this->league($competition['id'])];
            } catch (Throwable $exception) {
                report($exception);
                $rows[] = ['competition' => $competition['id'], 'status' => 'monitoring_unavailable', 'execution_enabled' => false];
            }
        }

        return $rows;
    }

    private function league(string $competition): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $state = $db->collector_states->findOne(['_id' => $competition], $options);
        $currentId = $state['current_season_id'] ?? null;
        if (! $currentId) {
            return [['competition' => $competition, 'status' => 'waiting_for_collection', 'execution_enabled' => false]];
        }
        $asOf = now('UTC')->toIso8601String();
        $links = iterator_to_array($db->provider_season_transitions->find(['competition' => $competition], $options), false);
        $ids = [$currentId];
        for ($i = 0; $i < 2; $i++) {
            $parents = array_values(array_filter($links, fn ($l) => $l['to_season_id'] === $ids[$i]));
            if (count($parents) !== 1) {
                break;
            }
            $ids[] = $parents[0]['from_season_id'];
        }
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

        $rows = array_map(fn ($strategy) => ['competition' => $competition, ...$this->monitor->evaluate($strategy,
            $seasons[$currentId], $seasons[$ids[1] ?? ''] ?? null, $seasons[$ids[2] ?? ''] ?? null, $links, $asOf)], ['champion-next-win', 'champion-next-season']);

        return array_map(fn ($row) => [...$row, 'simulation' => $this->replay->calculate($row, $currentCaptures,
            config('kiotrad.simulation_capital_minor'), $asOf)], $rows);
    }
}
