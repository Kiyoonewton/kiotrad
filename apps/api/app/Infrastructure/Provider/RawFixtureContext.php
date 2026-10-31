<?php

namespace App\Infrastructure\Provider;

/** Select one observed capture covering the whole requested odds response. */
final class RawFixtureContext
{
    public function find($captures, array $query, string $at, ?array $requiredIds = null): ?array
    {
        if ($requiredIds !== null) {
            $query['matches.provider_match_id'] = ['$all' => array_values(array_unique($requiredIds))];
        }
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $capture = $captures->findOne([...$query, 'observed_at' => ['$lte' => $at]],
            [...$options, 'sort' => ['observed_at' => -1, '_id' => 1]]);
        $earlier = $capture !== null;
        $capture ??= $captures->findOne($query, [...$options, 'sort' => ['observed_at' => 1, '_id' => 1]]);

        return $capture === null ? null : ['capture' => $capture, 'earlier' => $earlier];
    }

    public function ids(FeedObservation $feed): array
    {
        return array_map(fn ($event) => $event['id'] ?? '', $feed->document['data']['odds'] ?? []);
    }
}
