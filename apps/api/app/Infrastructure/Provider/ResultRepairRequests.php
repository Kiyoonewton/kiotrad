<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;

class ResultRepairRequests
{
    public function request(string $competition, string $season, string $match): void
    {
        DB::connection('mongodb')->getDatabase()->result_repair_requests->updateOne(['_id' => $competition.':'.$season], [
            '$setOnInsert' => ['competition' => $competition, 'season_id' => $season, 'requested_at' => now('UTC')->toIso8601String()],
            '$set' => ['status' => 'pending'], '$unset' => ['completed_at' => ''], '$addToSet' => ['provider_match_ids' => $match],
        ], ['upsert' => true]);
    }

    public function pending(string $competition): array
    {
        $rows = DB::connection('mongodb')->getDatabase()->result_repair_requests->find(['competition' => $competition, 'status' => 'pending'],
            ['projection' => ['season_id' => 1], 'sort' => ['requested_at' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);

        return array_map(fn ($row) => $row['season_id'], iterator_to_array($rows, false));
    }

    public function complete(string $competition, string $season): bool
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $key = $competition.':'.$season;
        $request = $db->result_repair_requests->findOne(['_id' => $key], $options);
        if ($request === null || $request['status'] !== 'pending') {
            return true;
        }
        $capture = $db->provider_captures->findOne(['competition' => $competition, 'season_id' => $season,
            'observed_at' => ['$lte' => now('UTC')->toIso8601String()]], [...$options, 'sort' => ['observed_at' => -1]]);
        $matches = array_column($capture['matches'] ?? [], null, 'provider_match_id');
        foreach ($request['provider_match_ids'] as $id) {
            $match = $matches[$id] ?? null;
            if ($match === null || $match['status'] !== 'settled' || ! in_array($match['result'] ?? null, ['H', 'D', 'A'], true)
                || ! ($match['scheduled_start_at'] ?? null) || $capture['observed_at'] < $match['scheduled_start_at']) {
                return false;
            }
        }

        return $db->result_repair_requests->updateOne(['_id' => $key, 'status' => 'pending', 'provider_match_ids' => $request['provider_match_ids']],
            ['$set' => ['status' => 'reconciled', 'completed_at' => now('UTC')->toIso8601String()]])->getModifiedCount() === 1;
    }
}
