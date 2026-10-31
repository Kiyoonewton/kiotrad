<?php

namespace App\Infrastructure\History;

use RuntimeException;

final class ProductionHistoryDescriptor
{
    public function publish(string $root, string $folder, string $at): string
    {
        $manifest = file_get_contents($folder.'/manifest.json');
        $plan = file_get_contents($folder.'/plan.json');
        $body = json_encode(['version' => 'production-history-package-1.0.0',
            'manifest_base64' => base64_encode($manifest), 'plan_base64' => base64_encode($plan),
            'manifest_sha256' => hash('sha256', $manifest), 'plan_sha256' => hash('sha256', $plan)], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);
        $envelope = ['schema_version' => 2, 'url' => 'kiotrad://legacy-history-package/'.hash('sha256', $manifest),
            'requested_at' => $at, 'received_at' => $at, 'http_status' => null,
            'body_sha256' => $hash, 'body_base64' => base64_encode($body),
            'provenance' => ['origin' => 'production_history_package', 'package_manifest_sha256' => hash('sha256', $manifest),
                'timestamp_basis' => 'package_import_bookkeeping_not_provider_retrieval']];
        $path = $root.'/'.$hash.'.json.gz';
        if (! is_file($path)) {
            $compressed = gzencode(json_encode($envelope, JSON_THROW_ON_ERROR), 6);
            $temporary = tempnam($root, '.package-');
            try {
                if ($temporary === false || $compressed === false || file_put_contents($temporary, $compressed) !== strlen($compressed)
                    || ! link($temporary, $path)) {
                    throw new RuntimeException('Cannot publish completed package descriptor.');
                }
            } finally {
                if ($temporary !== false && is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        $saved = json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        if (is_link($path) || ($saved['body_sha256'] ?? '') !== $hash
            || base64_decode($saved['body_base64'] ?? '', true) !== $body) {
            throw new RuntimeException('Completed package descriptor differs from immutable evidence.');
        }

        return $hash;
    }
}
