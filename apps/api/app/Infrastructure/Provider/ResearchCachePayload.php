<?php

namespace App\Infrastructure\Provider;

/** Versioned compression for disposable array-only calculation results. */
final class ResearchCachePayload
{
    private const PREFIX = 'kiotrad-gzip-v1:';

    public static function encode(array $value): string
    {
        return self::PREFIX.gzencode(serialize($value), 1);
    }

    public static function decode(mixed $value): ?array
    {
        // Read the previous uncompressed format during rolling upgrades.
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || ! str_starts_with($value, self::PREFIX)) {
            return null;
        }
        $body = @gzdecode(substr($value, strlen(self::PREFIX)), 67108864);
        if ($body === false) {
            return null;
        }
        $decoded = @unserialize($body, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : null;
    }
}
