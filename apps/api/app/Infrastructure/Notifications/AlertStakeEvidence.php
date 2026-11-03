<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class AlertStakeEvidence
{
    public function window(array $candidate, int $attempts, string $asOf): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $filter = ['competition' => $candidate['competition'], 'season_id' => $candidate['season_id'], 'observed_at' => ['$lte' => $asOf]];
        $firstDay = $candidate['next_match']['matchday'];
        if ($firstDay + $attempts - 1 > 30) {
            return [];
        }
        $fixtures = [];
        $seen = [];
        $previous = null;
        $opening = $db->provider_captures->findOne([...$filter, 'matches.matchday' => 1],
            [...$options, 'sort' => ['observed_at' => -1], 'projection' => ['matches' => 1]]);
        $starts = array_column(array_filter($opening['matches'] ?? [], fn ($m) => $m['matchday'] === 1), 'scheduled_start_at');
        if ($starts === [] || in_array(null, $starts, true)) {
            return [];
        }
        $seasonStart = min($starts);
        for ($day = $firstDay; $day < $firstDay + $attempts; $day++) {
            $capture = $db->provider_captures->findOne([...$filter, 'matches.matchday' => $day],
                [...$options, 'sort' => ['observed_at' => -1], 'projection' => ['matches' => 1]]);
            $matches = array_values(array_filter($capture['matches'] ?? [], fn ($m) => $m['matchday'] === $day
                && in_array($candidate['team_uid'], [$m['home_team_uid'], $m['away_team_uid']], true)));
            if (count($matches) !== 1 || empty($matches[0]['scheduled_start_at']) || $matches[0]['scheduled_start_at'] <= $asOf
                || in_array($day, $candidate['blocked_matchdays'] ?? [], true)) {
                return [];
            }
            $fixture = $matches[0];
            $id = $fixture['provider_match_id'] ?? '';
            if ($id === '' || isset($seen[$id]) || ($previous !== null && $fixture['scheduled_start_at'] <= $previous)) {
                return [];
            }
            $seen[$id] = true;
            $previous = $fixture['scheduled_start_at'];
            $outcome = $fixture['home_team_uid'] === $candidate['team_uid'] ? 'H' : 'A';
            $minimum = null;
            $rows = $db->fixture_odds_season_minima->find([
                'competition' => $candidate['competition'], 'season_id' => ['$ne' => $candidate['season_id']],
                'home_team_uid' => $fixture['home_team_uid'], 'away_team_uid' => $fixture['away_team_uid'],
                'season_start_at' => ['$type' => 'string', '$lt' => $seasonStart],
                'minima.'.$outcome.'.odds_scaled' => ['$gt' => 10000, '$lte' => 10000000],
                'minima.'.$outcome.'.observed_at' => ['$lte' => $asOf],
            ], [...$options, 'sort' => ['minima.'.$outcome.'.odds_scaled' => 1], 'limit' => 200]);
            foreach ($rows as $row) {
                $price = $row['minima'][$outcome];
                if ($db->provider_captures->countDocuments(['_id' => $price['capture_id'], 'observed_at' => ['$lte' => $asOf]], ['limit' => 1])) {
                    $minimum = $price;
                    break;
                }
            }
            if ($minimum === null) {
                return [];
            }
            $fixtures[] = [...array_intersect_key($fixture, array_flip(['provider_match_id', 'matchday', 'scheduled_start_at', 'home_team', 'away_team', 'home_team_uid', 'away_team_uid'])),
                'outcome' => $outcome, 'estimated_odds_scaled' => $minimum['odds_scaled'], 'historical_source' => $minimum,
                'schedule_capture_id' => (string) $capture['_id']];
        }

        return $fixtures;
    }
}
