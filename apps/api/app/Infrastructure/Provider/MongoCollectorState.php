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
        DB::connection('mongodb')->getDatabase()->selectCollection('collector_states')
            ->updateOne(['_id' => $competition], ['$set' => $state], ['upsert' => true]);
    }
}
