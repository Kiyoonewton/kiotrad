<?php

namespace App\Infrastructure\Notifications;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class StorageNotifications
{
    public function scan(NotificationOutbox $outbox): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        $control = $db->notification_control;
        $sample = $control->findOne(['_id' => 'storage']);
        $now = now('UTC')->toIso8601String();
        if (($sample['next_sample_at'] ?? '') > $now) {
            return;
        }
        $stats = $db->command(['dbStats' => 1])->toArray()[0];
        $bytes = (int) ($stats['storageSize'] ?? 0) + (int) ($stats['indexSize'] ?? 0);
        $root = storage_path('app/private/provider-evidence');
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        }
        $free = disk_free_space(is_dir($root) ? $root : storage_path());
        $usage = $bytes / (1024 ** 3);
        $control->updateOne(['_id' => 'storage'], ['$set' => ['sampled_at' => $now,
            'estimated_usage_bytes' => $bytes, 'evidence_filesystem_free_bytes' => $free,
            'next_sample_at' => now('UTC')->addHour()->toIso8601String()]], ['upsert' => true]);
        $threshold = 0;
        foreach (config('notifications.storage_thresholds_gib') as $value) {
            if ($usage >= $value) {
                $threshold = $value;
            }
        }
        if ($threshold || ($free !== false && $free / (1024 ** 3) < config('notifications.minimum_free_gib'))) {
            $outbox->enqueue('storage:'.substr($now, 0, 10).':'.$threshold, 'storage', [
                'Estimated KioTrad data GiB' => number_format($usage, 2),
                'Evidence filesystem free GiB' => $free === false ? 'Unknown' : number_format($free / (1024 ** 3), 2),
                'Planning budget GiB' => '80 — not an enforced quota',
                'Scope' => 'MongoDB allocated collections/indexes + evidence file lengths; excludes Docker images, journals and logs.',
                'Action' => 'Review disk capacity; no automatic data deletion.',
            ]);
        }
    }
}
