<?php

namespace App\Infrastructure\History;

use RuntimeException;

/** Compact distinct evidence, built once rather than rescanning captures per season. */
final class ProductionEvidenceIndex
{
    private array $fixtures = [];

    private array $quotes = [];

    public function load($db, array $ids): void
    {
        foreach (['matches' => 'fixtures', 'odds_snapshots' => 'quotes'] as $field => $property) {
            $value = $field === 'matches' ? '$'.$field : ['provider_match_id' => '$'.$field.'.provider_match_id', 'prices' => '$'.$field.'.prices'];
            $rows = $db->provider_captures->aggregate([
                ['$match' => [$field.'.provider_match_id' => ['$in' => $ids]]],
                ['$unwind' => '$'.$field],
                ['$match' => [$field.'.provider_match_id' => ['$in' => $ids]]],
                ['$group' => ['_id' => '$'.$field.'.provider_match_id', 'variants' => ['$addToSet' => ['competition' => '$competition', 'value' => $value]]]],
            ], ['allowDiskUse' => true, 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
            foreach ($rows as $row) {
                $this->{$property}[$row['_id']] = $row['variants'];
            }
        }
    }

    public function captures(array $ids): iterable
    {
        foreach ($ids as $id) {
            $times = [];
            foreach ($this->fixtures[$id] ?? [] as $variant) {
                if (! empty($variant['value']['scheduled_start_at'])) {
                    $times[$variant['value']['scheduled_start_at']] = true;
                }
                yield ['competition' => $variant['competition'], 'matches' => [$variant['value']]];
            }
            if (count($times) > 1) {
                throw new RuntimeException('Conflicting stored kickoff times: '.$id);
            }
            foreach ($this->quotes[$id] ?? [] as $variant) {
                yield ['competition' => $variant['competition'], 'odds_snapshots' => [$variant['value']]];
            }
        }
    }

    public function ensureLookupIndex($db): void
    {
        foreach ($db->provider_captures->listIndexes() as $index) {
            if (array_slice($index->getKey(), 0, 2, true) === ['competition' => 1, 'season_id' => 1]) {
                return;
            }
        }
        $db->provider_captures->createIndex(['competition' => 1, 'season_id' => 1], ['name' => 'history_competition_season']);
    }

    public function live($db, string $competition, string $season): iterable
    {
        return $db->provider_captures->find(['competition' => $competition, 'season_id' => $season], [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            'projection' => ['competition' => 1, 'matches' => 1, 'odds_snapshots' => 1],
        ]);
    }
}
