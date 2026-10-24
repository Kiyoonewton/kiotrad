<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;

final class NotificationWorker
{
    public function __construct(private NotificationOutbox $outbox, private SlackTransport $transport,
        private OperationalNotifications $operations, private OpportunityNotifications $opportunities,
        private PaperNotifications $paper, private StorageNotifications $storage) {}

    public function tick(): string
    {
        if (! $this->transport->configured()) {
            return 'disabled_or_unconfigured';
        }
        $control = DB::connection('mongodb')->getDatabase()->notification_control;
        $now = now('UTC')->toIso8601String();
        $control->updateOne(['_id' => 'worker'], ['$setOnInsert' => ['lease_until' => '', 'next_send_at' => '', 'next_scan_at' => '']], ['upsert' => true]);
        $token = bin2hex(random_bytes(16));
        $lease = $control->findOneAndUpdate(['_id' => 'worker', 'lease_until' => ['$lte' => $now]],
            ['$set' => ['token' => $token, 'lease_until' => now('UTC')->addMinutes(5)->toIso8601String()]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
        if (! $lease) {
            return 'worker_busy';
        }
        try {
            if ($lease['next_scan_at'] <= $now) {
                $since = $this->outbox->startedAt();
                $sources = [fn () => $this->operations->scan($this->outbox, $since),
                    fn () => $this->opportunities->scan($this->outbox), fn () => $this->paper->scan($this->outbox, $since),
                    fn () => $this->storage->scan($this->outbox)];
                $failures = 0;
                foreach ($sources as $source) {
                    try {
                        $source();
                    } catch (Throwable) {
                        $failures++; // Generic counters only; never log private configuration or feed payloads.
                    }
                }
                $control->updateOne(['_id' => 'worker', 'token' => $token], ['$set' => [
                    'last_scan_at' => $now, 'source_failures' => $failures,
                    'next_scan_at' => now('UTC')->addSeconds(15)->toIso8601String(),
                ]]);
            }
            if ($lease['next_send_at'] > $now) {
                return 'waiting';
            }
            $status = $this->outbox->deliver($this->transport);
            $control->updateOne(['_id' => 'worker', 'token' => $token], ['$set' => [
                'last_status' => $status, 'checked_at' => now('UTC')->toIso8601String(),
                'next_send_at' => now('UTC')->addSeconds(2)->toIso8601String(),
            ]]);

            return $status;
        } finally {
            $control->updateOne(['_id' => 'worker', 'token' => $token], ['$set' => ['lease_until' => ''], '$unset' => ['token' => '']]);
        }
    }
}
