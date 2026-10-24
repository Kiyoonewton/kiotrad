<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;
use MongoDB\Operation\FindOneAndUpdate;

class NotificationOutbox
{
    private bool $indexed = false;

    private function collection()
    {
        return DB::connection('mongodb')->getDatabase()->notification_outbox;
    }

    public function enqueue(string $key, string $type, array $details, ?string $expires = null): void
    {
        $id = hash('sha256', $key);
        $now = now('UTC')->toIso8601String();
        $this->collection()->updateOne(['_id' => $id], ['$setOnInsert' => [
            'type' => $type, 'payload' => app(SlackMessage::class)->make($type, $details, $id, $now),
            'created_at' => $now, 'next_attempt_at' => $now, 'status' => 'pending',
            'attempts' => 0, 'expires_at' => $expires,
        ]], ['upsert' => true]);
    }

    public function startedAt(): string
    {
        if (! $this->indexed) {
            $this->collection()->createIndex(['status' => 1, 'next_attempt_at' => 1, 'created_at' => 1]);
            $this->indexed = true;
        }
        $control = DB::connection('mongodb')->getDatabase()->notification_control;
        $control->updateOne(['_id' => 'main'], ['$setOnInsert' => ['started_at' => now('UTC')->toIso8601String()]], ['upsert' => true]);

        return $control->findOne(['_id' => 'main'])['started_at'];
    }

    public function deliver(SlackTransport $transport): string
    {
        if (! $transport->configured()) {
            return 'disabled_or_unconfigured';
        }
        $now = now('UTC')->toIso8601String();
        $pause = DB::connection('mongodb')->getDatabase()->notification_control->findOne(['_id' => 'slack-rate']);
        if (($pause['paused_until'] ?? '') > $now) {
            return 'waiting';
        }
        $lease = bin2hex(random_bytes(16));
        $row = $this->collection()->findOneAndUpdate([
            'status' => ['$in' => ['pending', 'retry', 'sending']], 'next_attempt_at' => ['$lte' => $now],
        ], ['$set' => ['status' => 'sending', 'lease' => $lease,
            'next_attempt_at' => now('UTC')->addSeconds(60)->toIso8601String()], '$inc' => ['attempts' => 1]],
            ['sort' => ['created_at' => 1, '_id' => 1], 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if (! $row) {
            return 'waiting';
        }
        $result = $row['expires_at'] !== null && $row['expires_at'] <= $now
            ? ['status' => 'expired', 'reason' => 'opportunity_window_closed'] : $transport->send($row['payload']);
        $delay = max($result['delay'] ?? 0, min(3600, 30 * (2 ** min(7, $row['attempts'] - 1))));
        if (($result['reason'] ?? '') === 'rate_limited') {
            DB::connection('mongodb')->getDatabase()->notification_control->updateOne(['_id' => 'slack-rate'],
                ['$set' => ['paused_until' => now('UTC')->addSeconds($delay)->toIso8601String()]], ['upsert' => true]);
        }
        $this->collection()->updateOne(['_id' => $row['_id'], 'lease' => $lease], ['$set' => [
            'status' => $result['status'], 'last_reason' => $result['reason'] ?? null,
            'last_attempt_at' => $now, 'next_attempt_at' => now('UTC')->addSeconds($delay)->toIso8601String(),
            'sent_at' => $result['status'] === 'sent' ? now('UTC')->toIso8601String() : null,
        ], '$unset' => ['lease' => '']]);

        return $result['status'];
    }

    public function retryBlocked(): int
    {
        return $this->collection()->updateMany(['status' => 'blocked'], ['$set' => [
            'status' => 'retry', 'next_attempt_at' => now('UTC')->toIso8601String(),
        ]])->getModifiedCount();
    }
}
