<?php

namespace App\Infrastructure\History;

use RuntimeException;

final class ProductionHistoryStore
{
    public const VERSION = 'reviewed-production-history-1.0.0';

    public function digest(array $value): string
    {
        unset($value['_id']);
        $canonical = function (array $row) use (&$canonical): array {
            if (! array_is_list($row)) {
                ksort($row);
            }
            foreach ($row as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $row;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function record($db, string $key): ?array
    {
        return $db->production_history_imports->findOne(['_id' => $key], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    public function prepare($db, string $key, array $plan, array $proof): array
    {
        $db->production_history_imports->updateOne(['_id' => $key], ['$setOnInsert' => [
            'kind' => 'season', 'status' => 'prepared', 'plan' => $plan, 'proof' => $proof,
        ]], ['upsert' => true]);
        $record = $this->record($db, $key);
        if ($this->digest($record['plan']) !== $this->digest($plan) || $record['proof'] !== $proof) {
            throw new RuntimeException('Prepared import differs from the pinned season plan.');
        }

        return $record;
    }

    public function materialize($db, string $key, array $plan): void
    {
        if ($plan['new_matches'] + $plan['new_prices'] === 0) {
            return;
        }
        $id = hash('sha256', $key);
        $capture = $plan['capture'];
        $db->provider_captures->updateOne(['_id' => $id], ['$setOnInsert' => $capture], ['upsert' => true]);
        $saved = $db->provider_captures->findOne(['_id' => $id], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if ($this->digest($saved) !== $this->digest($capture)) {
            throw new RuntimeException('Existing deterministic capture differs; no overwrite permitted.');
        }
        $checkpoint = $capture['competition'].':'.$capture['season_id'];
        $db->season_checkpoints->updateOne(['_id' => $checkpoint], ['$setOnInsert' => [
            'capture_id' => $id, 'season_id' => $capture['season_id'], 'observed_at' => $capture['observed_at'],
        ]], ['upsert' => true]);
        // One atomic revision bump per import key, including crash retries.
        $db->season_checkpoints->updateOne(['_id' => $checkpoint, 'production_history_keys' => ['$ne' => $key]], [
            '$inc' => ['odds_evidence_revision' => 1], '$addToSet' => ['production_history_keys' => $key],
        ]);
    }

    public function verifyComplete($db, string $key, array $record): void
    {
        $plan = $record['plan'];
        if ($plan['new_matches'] + $plan['new_prices'] === 0) {
            return;
        }
        $this->verifyCapture($db, $key, $plan);
        $pointer = $db->season_checkpoints->findOne(['_id' => $plan['capture']['competition'].':'.$plan['capture']['season_id']], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if ($pointer === null || ! in_array($key, (array) ($pointer['production_history_keys'] ?? []), true)) {
            throw new RuntimeException('Completed import checkpoint is missing; review required.');
        }
    }

    public function verifyCapture($db, string $key, array $plan): void
    {
        $capture = $db->provider_captures->findOne(['_id' => hash('sha256', $key)], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if ($capture === null || $this->digest($capture) !== $this->digest($plan['capture'])) {
            throw new RuntimeException('Pinned immutable import capture is missing or changed.');
        }
    }
}
