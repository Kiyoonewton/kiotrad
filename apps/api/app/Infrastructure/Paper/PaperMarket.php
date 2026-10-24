<?php

namespace App\Infrastructure\Paper;

use Illuminate\Support\Facades\DB;

class PaperMarket
{
    public function facts(array $requests, string $now): array
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $facts = [];
        foreach ($requests as $request) {
            $key = implode(':', [$request['competition'], $request['season_id'], $request['team_uid'], $request['day']]);
            if (isset($facts[$key])) {
                continue;
            }
            $filter = ['competition' => $request['competition'], 'season_id' => $request['season_id'], 'observed_at' => ['$lte' => $now]];
            $capture = $db->provider_captures->findOne($filter, [...$options, 'sort' => ['observed_at' => -1],
                'projection' => ['matches' => 1, 'observed_at' => 1]]);
            if ($capture === null) {
                continue;
            }
            $matches = array_values(array_filter($capture['matches'], fn ($m) => $m['matchday'] === $request['day']
                && in_array($request['team_uid'], [$m['home_team_uid'], $m['away_team_uid']], true)));
            if (count($matches) !== 1) {
                continue;
            }
            $latest = ['fixture' => $matches[0], 'observed_at' => $capture['observed_at'], 'capture_id' => $capture['_id']];
            $price = null;
            $captures = $db->provider_captures->find([...$filter, 'odds_snapshots.provider_match_id' => $matches[0]['provider_match_id']],
                [...$options, 'projection' => ['odds_snapshots' => 1, 'observed_at' => 1]]);
            foreach ($captures as $priced) {
                foreach ($priced['odds_snapshots'] as $snapshot) {
                    if ($price !== null && $snapshot['provider_match_id'] === $latest['fixture']['provider_match_id']
                        && $snapshot['captured_at'] === $price['captured_at']
                        && ($snapshot['prices'] !== $price['prices'] || $snapshot['pre_match_verified'] !== $price['pre_match_verified'])) {
                        $price['pre_match_verified'] = false; // Same-second conflicts cannot establish the latest valid quote.
                    }
                    if ($snapshot['provider_match_id'] === $latest['fixture']['provider_match_id'] && $snapshot['captured_at'] <= $now
                        && ($price === null || $snapshot['captured_at'] > $price['captured_at'])) {
                        $price = [...$snapshot, 'capture_id' => $priced['_id'], 'stored_at' => $priced['observed_at']];
                    }
                }
            }
            if ($latest !== null) {
                $facts[$key] = [...$latest, 'price' => $price];
            }
        }

        return $facts;
    }
}
