<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;

final class MongoCollectorState implements CollectorState
{
    public function read(string $competition): array
    {
        $row = DB::connection('mongodb')->getDatabase()->selectCollection('collector_states')
            ->findOne(['_id' => $competition], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        return $row ?? [];
    }

    public function write(string $competition, array $state): void
    {
        if (isset($state['season_transition'])) {
            $transition = ['competition' => $competition, ...$state['season_transition']];
            $key = $competition.':'.$transition['from_season_id'].':'.$transition['to_season_id'];
            DB::connection('mongodb')->getDatabase()->selectCollection('provider_season_transitions')
                ->updateOne(['_id' => $key], ['$setOnInsert' => $transition], ['upsert' => true]);
        }
        DB::connection('mongodb')->getDatabase()->selectCollection('collector_states')
            ->updateOne(['_id' => $competition], ['$set' => $state], ['upsert' => true]);
    }
}
