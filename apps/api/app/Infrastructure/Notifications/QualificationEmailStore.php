<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class QualificationEmailStore
{
    protected function collectionName(): string
    {
        return 'qualification_email_alerts';
    }

    public function claim(array $alert): bool
    {
        // An interrupted or ambiguous SMTP submission is never automatically resent.
        // Insert-only identity survives restarts and multiple workers.
        $result = DB::connection('mongodb')->getDatabase()->selectCollection($this->collectionName())->updateOne(
            ['_id' => $alert['id']], ['$setOnInsert' => ['status' => 'sending', 'created_at' => now('UTC')->toIso8601String(),
                'deadline' => $alert['deadline'], 'details' => $alert['details'], 'stake_plan' => $alert['stake_plan'] ?? null]], ['upsert' => true]);

        return $result->getUpsertedCount() === 1;
    }

    public function finish(string $id, string $status): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection($this->collectionName())->updateOne(['_id' => $id],
            ['$set' => ['status' => $status, 'updated_at' => now('UTC')->toIso8601String()]]);
    }
}
