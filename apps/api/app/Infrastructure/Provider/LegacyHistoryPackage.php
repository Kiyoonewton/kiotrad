<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class LegacyHistoryPackage
{
    public function entries(string $folder): array
    {
        $root = realpath($folder);
        if ($root === false || ! is_dir($root) || is_link($folder)) {
            throw new RuntimeException('Expected a regular history package directory.');
        }
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['package_version'] ?? '') !== 'legacy-history-package-1.0.0'
            || ! is_array($manifest['files'] ?? null) || $manifest['files'] === []
            || ! hash_equals((string) ($manifest['plan_sha256'] ?? ''), hash_file('sha256', $root.'/plan.json'))) {
            throw new RuntimeException('Invalid or incomplete history package.');
        }
        $seen = [];
        $entries = [];
        foreach ($manifest['files'] as $entry) {
            $hash = $entry['sha256'] ?? '';
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
                || ($entry['file'] ?? '') !== $hash.'.json' || isset($seen[$hash])) {
                throw new RuntimeException('Unsafe or duplicate package entry.');
            }
            $seen[$hash] = true;
            $path = $root.'/files/'.$entry['file'];
            if (is_link($root.'/files') || is_link($path) || ! is_file($path) || filesize($path) > 20971520
                || ! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Source checksum mismatch or unsafe file.');
            }
            $entries[] = [...$entry, 'path' => $path];
        }

        return $entries;
    }
}
