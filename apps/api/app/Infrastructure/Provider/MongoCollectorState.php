<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\SimulationSeasonLinks;
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
        if (! empty($state['season_catalog'])) {
            $operations = [];
            foreach ((new SimulationSeasonLinks)->merge([], $state['season_catalog'], $state['collection_observed_at']) as $link) {
                $key = $competition.':'.$link['from_season_id'].':'.$link['to_season_id'];
                $operations[] = ['updateOne' => [['_id' => $key], ['$setOnInsert' => ['competition' => $competition, ...$link]], ['upsert' => true]]];
            }
            if ($operations !== []) {
                DB::connection('mongodb')->getDatabase()->provider_catalog_links->bulkWrite($operations, ['ordered' => false]);
            }
        }
        foreach (['collection_gap', 'last_collection_gap'] as $field) {
            if (! isset($state[$field])) {
                continue;
            }
            $gap = $state[$field];
            $sealed = array_intersect_key($gap, array_flip(['expected_at', 'last_observed_at', 'detected_at', 'reason']));
            $mutable = array_diff_key($gap, $sealed, ['id' => true]);
            DB::connection('mongodb')->getDatabase()->collection_gaps->updateOne(['_id' => $gap['id']],
                ['$setOnInsert' => ['competition' => $competition, ...$sealed], '$set' => $mutable], ['upsert' => true]);
        }
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
