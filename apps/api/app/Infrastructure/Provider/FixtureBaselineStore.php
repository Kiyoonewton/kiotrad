<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\FixtureOddsBaseline;
use Illuminate\Support\Facades\DB;

class FixtureBaselineStore
{
    private array $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function refresh(string $competition): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $db->fixture_odds_season_minima->createIndex(['competition' => 1, 'home_team_uid' => 1, 'away_team_uid' => 1, 'season_start_at' => 1]);
        $changed = 0;
        foreach ($db->season_checkpoints->find(['_id' => ['$regex' => '^'.preg_quote($competition, '/').':']], $this->options) as $checkpoint) {
            $season = (string) $checkpoint['season_id'];
            $key = $competition.':'.$season;
            $revision = hash('sha256', $checkpoint['capture_id'].':'.FixtureOddsBaseline::VERSION);
            $saved = $db->fixture_baseline_progress->findOne(['_id' => $key], $this->options);
            if (($saved['revision'] ?? null) === $revision) {
                continue;
            }
            $captures = $db->provider_captures->find(['competition' => $competition, 'season_id' => $season],
                [...$this->options, 'batchSize' => 5, 'projection' => ['matches' => 1, 'odds_snapshots' => 1], 'sort' => ['observed_at' => 1]]);
            $rows = (new FixtureOddsBaseline)->fold($captures, $competition, $season);
            foreach ($rows as $row) {
                $id = $key.':'.$row['home_team_uid'].':'.$row['away_team_uid'];
                $db->fixture_odds_season_minima->updateOne(['_id' => $id], ['$set' => $row], ['upsert' => true]);
            }
            // Mark complete only after every pairing for this source revision is durable.
            $db->fixture_baseline_progress->updateOne(['_id' => $key], ['$set' => ['revision' => $revision,
                'competition' => $competition, 'season_id' => $season, 'updated_at' => now('UTC')->toIso8601String()]], ['upsert' => true]);
            $changed++;
        }
        $pairs = [];
        foreach ($db->fixture_odds_season_minima->find(['competition' => $competition], [...$this->options, 'batchSize' => 20]) as $row) {
            $key = $competition.':'.$row['home_team_uid'].':'.$row['away_team_uid'];
            $pairs[$key] ??= array_intersect_key($row, array_flip(['competition', 'home_team_uid', 'away_team_uid', 'home_team', 'away_team']));
            foreach ($row['minima'] as $outcome => $price) {
                if (! isset($pairs[$key]['minima'][$outcome]) || $price['odds_scaled'] < $pairs[$key]['minima'][$outcome]['odds_scaled']) {
                    $pairs[$key]['minima'][$outcome] = $price;
                }
            }
        }
        foreach ($pairs as $id => $row) {
            $db->fixture_odds_minima->updateOne(['_id' => $id], ['$set' => [...$row, 'policy_version' => FixtureOddsBaseline::VERSION]], ['upsert' => true]);
        }

        return ['updated_seasons' => $changed, 'priced_pairings' => count($pairs), 'expected_pairings' => 240];
    }

    public function before(string $competition, array $fixture, string $outcome, string $seasonStart): ?array
    {
        $cursor = DB::connection('mongodb')->getDatabase()->fixture_odds_season_minima->find([
            'competition' => $competition, 'home_team_uid' => $fixture['home_team_uid'], 'away_team_uid' => $fixture['away_team_uid'],
            'season_start_at' => ['$type' => 'string', '$lt' => $seasonStart],
            'minima.'.$outcome.'.odds_scaled' => ['$gt' => 10000],
        ], [...$this->options, 'sort' => ['minima.'.$outcome.'.odds_scaled' => 1, 'season_start_at' => 1], 'limit' => 1]);
        foreach ($cursor as $row) {
            return $row['minima'][$outcome];
        }

        return null;
    }
}
