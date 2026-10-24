<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;

final class MongoCollectionStore implements CollectionStore
{
    public function save(array $report): string
    {
        $database = DB::connection('mongodb')->getDatabase();
        // One immutable normalized capture. A retry of the same report has the same identity.
        // No transaction is required: the capture is durable before the checkpoint advances.
        $capture = array_intersect_key($report, array_flip([
            'competition', 'season_id', 'observed_at', 'matches', 'odds_snapshots',
            'fixture_count', 'settled_count', 'odds_matchday', 'verified_pre_match_prices',
        ]));
        $capture['schema_version'] = 1;
        $id = hash('sha256', json_encode($capture, JSON_THROW_ON_ERROR));
        $database->selectCollection('provider_captures')->updateOne(
            ['_id' => $id], ['$setOnInsert' => $capture], ['upsert' => true],
        );
        // Do not replace the latest pointer with an older capture on delayed retries.
        $checkpoints = $database->selectCollection('provider_checkpoints');
        $checkpoints->updateOne(['_id' => $report['competition']], [
            '$setOnInsert' => ['observed_at' => '', 'capture_id' => null],
        ], ['upsert' => true]);
        $checkpoints->updateOne([
            '_id' => $report['competition'], 'observed_at' => ['$lte' => $report['observed_at']],
        ], ['$set' => [
            'observed_at' => $report['observed_at'], 'capture_id' => $id,
            'season_id' => $report['season_id'],
        ]]);

        return $id;
    }
}
