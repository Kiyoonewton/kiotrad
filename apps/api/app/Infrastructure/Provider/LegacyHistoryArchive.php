<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class LegacyHistoryArchive
{
    public function preserve(string $root, array $files, string $at): array
    {
        if (! is_dir($root) || is_link($root) || ! is_writable($root)) {
            throw new RuntimeException('Mount an existing writable persistent history archive directory.');
        }
        $saved = [];
        foreach ($files as $file) {
            $body = file_get_contents($file['path']);
            $hash = hash('sha256', $body);
            if (! hash_equals($file['sha256'], $hash)) {
                throw new RuntimeException('History source changed after validation.');
            }
            $path = rtrim($root, '/').'/'.$hash.'.json.gz';
            if (! is_file($path)) {
                $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $envelope = ['schema_version' => 2, 'url' => config('provider.odds_base').$payload['queryUrl'], 'requested_at' => null, 'received_at' => null,
                    'http_status' => null, 'imported_at' => $at, 'body_sha256' => $hash,
                    'body_base64' => base64_encode($body), 'provenance' => [
                        'origin' => 'legacy_file', 'history_import_version' => LegacyHistoryPlan::VERSION,
                        'source_filename' => $file['source_filename'] ?? $file['file'],
                        'source_sha256' => $hash, 'original_received_at' => null,
                        'timestamp_basis' => 'import_time_original_retrieval_unknown']];
                $compressed = gzencode(json_encode($envelope, JSON_THROW_ON_ERROR), 6);
                $temporary = tempnam($root, '.history-');
                try {
                    if ($temporary === false || $compressed === false
                        || file_put_contents($temporary, $compressed) !== strlen($compressed)
                        || ! link($temporary, $path)) {
                        throw new RuntimeException('Raw history archive could not be published.');
                    }
                } finally {
                    if ($temporary !== false && is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            if (is_link($path)) {
                throw new RuntimeException('History archive may not contain symlinks.');
            }
            $record = json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
            $restored = base64_decode($record['body_base64'] ?? '', true);
            if ($restored === false || $record['body_sha256'] !== $hash || ! hash_equals($hash, hash('sha256', $restored))) {
                throw new RuntimeException('Existing history archive checksum mismatch.');
            }
            $saved[] = $hash;
        }

        return $saved;
    }
}
