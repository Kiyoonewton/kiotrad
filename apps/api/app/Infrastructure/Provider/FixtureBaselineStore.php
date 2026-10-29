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
        $progress = [];
        foreach ($db->fixture_baseline_progress->find(['competition' => $competition], $this->options) as $saved) {
            $progress[$saved['_id']] = $saved['revision'];
        }
        foreach ($db->season_checkpoints->find(['_id' => ['$regex' => '^'.preg_quote($competition, '/').':']], $this->options) as $checkpoint) {
            $season = (string) $checkpoint['season_id'];
            $key = $competition.':'.$season;
            $revision = hash('sha256', $checkpoint['capture_id'].':'.($checkpoint['odds_evidence_revision'] ?? 0).':'.FixtureOddsBaseline::VERSION);
            if (($progress[$key] ?? null) === $revision) {
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
        $existingCount = $db->fixture_odds_minima->countDocuments(['competition' => $competition]);
        if ($changed === 0 && $existingCount > 0) {
            return ['updated_seasons' => 0, 'priced_pairings' => $existingCount, 'expected_pairings' => 240];
        }
        // Reduce inside MongoDB; transfer one minimum per pair/outcome, not every season.
        $cursor = $db->fixture_odds_season_minima->aggregate([
            ['$match' => ['competition' => $competition, 'minima' => ['$type' => 'object']]],
            ['$project' => ['competition' => 1, 'home_team_uid' => 1, 'away_team_uid' => 1,
                'home_team' => 1, 'away_team' => 1, 'season_start_at' => 1,
                'minimum' => ['$objectToArray' => '$minima']]],
            ['$unwind' => '$minimum'], ['$match' => ['minimum.v.odds_scaled' => ['$gt' => 10000]]],
            ['$sort' => ['minimum.v.odds_scaled' => 1, 'season_start_at' => 1, '_id' => 1]],
            ['$group' => ['_id' => ['home' => '$home_team_uid', 'away' => '$away_team_uid', 'outcome' => '$minimum.k'],
                'row' => ['$first' => '$$ROOT']]],
        ], [...$this->options, 'allowDiskUse' => true]);
        $pairs = [];
        foreach ($cursor as $group) {
            $row = $group['row'];
            $key = $competition.':'.$row['home_team_uid'].':'.$row['away_team_uid'];
            $pairs[$key] ??= array_intersect_key($row, array_flip(['competition', 'home_team_uid', 'away_team_uid', 'home_team', 'away_team']));
            $pairs[$key]['minima'][$row['minimum']['k']] = $row['minimum']['v'];
        }
        foreach ($pairs as $id => $row) {
            $db->fixture_odds_minima->updateOne(['_id' => $id], ['$set' => [...$row, 'policy_version' => FixtureOddsBaseline::VERSION]], ['upsert' => true]);
        }

        $db->fixture_odds_minima->deleteMany(['competition' => $competition, '_id' => ['$nin' => array_keys($pairs)]]);

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
