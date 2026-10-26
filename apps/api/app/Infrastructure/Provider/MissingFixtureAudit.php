<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\FixtureOddsBaseline;
use Illuminate\Support\Facades\DB;

final class MissingFixtureAudit
{
    public function inspect(array $runs): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $report = [];
        foreach ($runs as $run) {
            foreach ($run['missing_historical_fixtures'] ?? [] as $fixture) {
                $id = $fixture['provider_match_id'];
                $match = $db->provider_captures->findOne(['competition' => $run['competition'],
                    'season_id' => $run['season_id'], 'matches.provider_match_id' => $id],
                    [...$options, 'projection' => ['matches' => 1]]);
                $exact = null;
                $seasonStart = $run['season_start_at'] ?? null;
                foreach ($match['matches'] ?? [] as $m) {
                    if ($m['provider_match_id'] === $id) {
                        $exact = $m;
                    }
                }
                if ($exact === null || $seasonStart === null) {
                    $report[] = ['run_id' => $run['id'], 'fixture' => $fixture, 'reason' => 'fixture_evidence_unavailable'];

                    continue;
                }
                // Use the same prior-season cutoff as sizing, never later prices.
                $cursor = $db->provider_captures->find(['competition' => $run['competition'],
                    'matches' => ['$elemMatch' => ['home_team_uid' => $exact['home_team_uid'],
                        'away_team_uid' => $exact['away_team_uid'], 'scheduled_start_at' => ['$lt' => $seasonStart]]]],
                    [...$options, 'projection' => ['matches' => 1, 'odds_snapshots' => 1, 'season_id' => 1], 'batchSize' => 5]);
                $raw = null;
                $captures = 0;
                foreach ($cursor as $capture) {
                    $captures++;
                    foreach ((new FixtureOddsBaseline)->fold([$capture], $run['competition'], (string) $capture['season_id']) as $pair) {
                        if ($pair['home_team_uid'] !== $exact['home_team_uid'] || $pair['away_team_uid'] !== $exact['away_team_uid']
                            || $pair['season_start_at'] >= $seasonStart) {
                            continue;
                        }
                        $price = $pair['minima'][$fixture['outcome']] ?? null;
                        if ($price !== null && ($raw === null || $price['odds_scaled'] < $raw['odds_scaled'])) {
                            $raw = $price;
                        }
                    }
                }
                $baseline = app(FixtureBaselineStore::class)->before($run['competition'], $exact, $fixture['outcome'], $seasonStart);
                $report[] = ['run_id' => $run['id'], 'fixture' => $fixture, 'cutoff' => $seasonStart,
                    'matching_prior_captures' => $captures, 'raw_minimum' => $raw, 'derived_minimum' => $baseline,
                    'reason' => $baseline !== null ? 'dashboard_snapshot_needs_refresh'
                        : ($raw !== null ? 'baseline_rebuild_needed' : 'no_matching_earlier_raw_price_found')];
            }
        }

        return $report;
    }
}
