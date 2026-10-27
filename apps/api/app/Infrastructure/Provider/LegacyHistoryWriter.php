<?php

namespace App\Infrastructure\Provider;

final class LegacyHistoryWriter
{
    public function apply($db, string $key, array $plan): array
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        // Pin the normalized plan before insert. Crash retries reuse it, even if the
        // capture insert succeeded before checkpoint/ledger completion.
        $db->local_history_imports->updateOne(['_id' => $key], ['$setOnInsert' => [
            'status' => 'prepared', 'plan' => $plan]], ['upsert' => true]);
        $record = $db->local_history_imports->findOne(['_id' => $key], $options);
        if ($record['status'] === 'complete') {
            return ['status' => 'already_imported', 'new_matches' => 0, 'new_prices' => 0];
        }
        $plan = $record['plan'];
        $capture = $plan['capture'];
        if ($plan['new_matches'] > 0 || $plan['new_prices'] > 0) {
            $id = hash('sha256', $key);
            $db->provider_captures->updateOne(['_id' => $id], ['$setOnInsert' => $capture], ['upsert' => true]);
            $checkpoint = $capture['competition'].':'.$capture['season_id'];
            // Existing fixture checkpoint stays intact, preserving its full verified schedule.
            $db->season_checkpoints->updateOne(['_id' => $checkpoint], ['$setOnInsert' => [
                'capture_id' => $id, 'observed_at' => $capture['observed_at'], 'season_id' => $capture['season_id'],
            ]], ['upsert' => true]);
            $db->season_checkpoints->updateOne(['_id' => $checkpoint], ['$inc' => ['odds_evidence_revision' => 1]]);
        }
        $db->local_history_imports->updateOne(['_id' => $key], ['$set' => ['status' => 'complete']]);

        return ['status' => $plan['new_matches'] + $plan['new_prices'] > 0 ? 'imported' : 'raw_preserved_only', 'new_matches' => $plan['new_matches'], 'new_prices' => $plan['new_prices'],
            'ambiguous_price_match_ids' => $plan['ambiguous_price_match_ids']];
    }
}
