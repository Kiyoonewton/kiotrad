<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

class AvailabilityEvidence
{
    public function record(string $competition, string $season, int $round, ?FeedObservation $feed): void
    {
        $directory = storage_path('app/private/provider-evidence');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private evidence directory.');
        }
        // Only public provider payloads; no cookies, credentials or exception messages.
        $data = ['competition' => $competition, 'season_id' => $season, 'matchday' => $round,
            'observed_at' => now('UTC')->toIso8601String(), 'classification' => 'availability_unknown',
            'response' => $feed?->evidence()];
        $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $path = $directory.'/'.hash('sha256', $contents).'.json';
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot save private availability evidence.');
        }
        chmod($path, 0600);
    }
}
