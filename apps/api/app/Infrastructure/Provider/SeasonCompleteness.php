<?php

namespace App\Infrastructure\Provider;

final class SeasonCompleteness
{
    public function inspect(array $matches): array
    {
        $days = [];
        $identities = [];
        $teams = [];
        $pairings = [];
        $valid = count($matches) === 240;
        foreach ($matches as $match) {
            $day = $match['matchday'];
            $id = $match['provider_match_id'];
            $home = $match['home_team_uid'];
            $away = $match['away_team_uid'];
            $pair = $home.':'.$away;
            $valid = $valid && ! isset($pairings[$pair]);
            $pairings[$pair] = true;
            $valid = $valid && ! isset($identities[$id]);
            $identities[$id] = true;
            $days[$day]['count'] = ($days[$day]['count'] ?? 0) + 1;
            foreach ([$home, $away] as $team) {
                $valid = $valid && ! isset($days[$day]['teams'][$team]);
                $days[$day]['teams'][$team] = true;
                $teams[$team] = true;
            }
        }
        $missing = [];
        foreach (range(1, 30) as $day) {
            if (($days[$day]['count'] ?? 0) !== 8 || count($days[$day]['teams'] ?? []) !== 16) {
                $missing[] = $day;
            }
        }
        $fixtures = $valid && $missing === [] && count($teams) === 16;
        $settled = count(array_filter($matches, fn ($m) => $m['status'] === 'settled'));

        return [
            'fixtures_complete' => $fixtures,
            'missing_or_invalid_matchdays' => $missing,
            'results_complete' => $fixtures && $settled === 240,
            'scheduled_times_complete' => $fixtures && count(array_filter($matches, fn ($m) => $m['scheduled_start_at'] !== null)) === 240,
            'strategy_ready' => false,
        ];
    }
}
