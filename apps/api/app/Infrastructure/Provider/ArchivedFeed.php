<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use RuntimeException;

final class ArchivedFeed
{
    public function read(string $path): array
    {
        $record = json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        $body = base64_decode($record['body_base64'] ?? '', true);
        if ($body === false || ! hash_equals($record['body_sha256'] ?? '', hash('sha256', $body))) {
            throw new RuntimeException('Raw evidence checksum mismatch.');
        }
        $legacy = ($record['provenance']['origin'] ?? null) === 'legacy_file';
        if ($legacy && (($record['requested_at'] ?? null) !== null || ($record['received_at'] ?? null) !== null
            || ($record['provenance']['source_sha256'] ?? '') !== hash('sha256', $body))) {
            throw new RuntimeException('Legacy provenance is inconsistent.');
        }
        $requested = CarbonImmutable::parse($legacy ? $record['imported_at'] : $record['requested_at']);
        $received = CarbonImmutable::parse($legacy ? $record['imported_at'] : $record['received_at']);
        if ($requested->greaterThan($received)) {
            throw new RuntimeException('Raw evidence timestamps are inconsistent.');
        }
        $payload = json_decode($body, true);
        $document = $payload['doc'][0] ?? [];

        return ['body_sha256' => hash('sha256', $body), 'status' => $legacy ? 200 : $record['http_status'], 'feed' => new FeedObservation($record['url'], $requested, $received,
            is_array($document) ? $document : [], is_array($payload) ? $payload : [], in_array($record['provenance']['origin'] ?? '', ['legacy_file', 'production_history_package'], true) ? $record['provenance'] : [])];
    }
}
