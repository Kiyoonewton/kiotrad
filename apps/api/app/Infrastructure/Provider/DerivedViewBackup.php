<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Expiring, rebuildable last-good views; never provider evidence or a ledger. */
final class DerivedViewBackup
{
    public function read(string $key): ?array
    {
        try {
            $disk = Storage::disk('local');
            $path = 'dashboard-views/'.hash('sha256', $key).'.json';
            if (! $disk->exists($path)) {
                return null;
            }
            $record = json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);
            if (($record['key'] ?? null) !== $key || ! is_array($record['data'] ?? null)
                || CarbonImmutable::parse($record['expires_at'])->lessThanOrEqualTo(now('UTC'))) {
                return null;
            }

            return $record['data'];
        } catch (Throwable) {
            return null;
        }
    }

    public function write(string $key, array $data): void
    {
        $temporary = null;
        try {
            $disk = Storage::disk('local');
            $path = 'dashboard-views/'.hash('sha256', $key).'.json';
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            $record = ['key' => $key, 'expires_at' => now('UTC')->addSeconds(SimulationCache::RECENT_TTL)->toIso8601String(), 'data' => $data];
            if ($disk->put($temporary, json_encode($record, JSON_THROW_ON_ERROR))) {
                $disk->move($temporary, $path); // Atomic local rename: readers never see half a view.
            }
            foreach ($disk->files('dashboard-views') as $file) {
                if ($disk->lastModified($file) < now('UTC')->timestamp - SimulationCache::RECENT_TTL) {
                    $disk->delete($file);
                }
            }
        } catch (Throwable $exception) {
            report($exception); // Redis publication can still succeed if the backup disk fails.
        } finally {
            if ($temporary !== null) {
                try {
                    Storage::disk('local')->delete($temporary);
                } catch (Throwable) {
                }
            }
        }
    }
}
