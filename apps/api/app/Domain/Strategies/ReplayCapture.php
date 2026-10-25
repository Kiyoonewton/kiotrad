<?php

namespace App\Domain\Strategies;

final class ReplayCapture
{
    public function forTeam(array $capture, string $team): ?array
    {
        $matches = array_values(array_filter($capture['matches'], fn ($match) => in_array($team,
            [$match['home_team_uid'], $match['away_team_uid']], true)));
        if ($matches === []) {
            return null;
        }
        $fields = array_flip(['provider_match_id', 'matchday', 'home_team_uid', 'away_team_uid',
            'home_team', 'away_team', 'scheduled_start_at', 'status', 'result']);
        $matches = array_map(fn ($match) => array_intersect_key($match, $fields), $matches);
        $ids = array_column($matches, 'provider_match_id');

        return ['_id' => $capture['_id'], 'observed_at' => $capture['observed_at'],
            'matches' => $matches, 'odds_snapshots' => array_values(array_filter($capture['odds_snapshots'] ?? [],
                fn ($price) => in_array($price['provider_match_id'], $ids, true)))];
    }
}
