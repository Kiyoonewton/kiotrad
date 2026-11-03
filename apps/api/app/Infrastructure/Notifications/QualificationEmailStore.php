<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class QualificationEmailStore
{
    public function claim(array $alert): bool
    {
        // An interrupted or ambiguous SMTP submission is never automatically resent.
        // Insert-only identity survives restarts and multiple workers.
        $result = DB::connection('mongodb')->getDatabase()->qualification_email_alerts->updateOne(
            ['_id' => $alert['id']], ['$setOnInsert' => ['status' => 'sending', 'created_at' => now('UTC')->toIso8601String(),
                'deadline' => $alert['deadline'], 'details' => $alert['details']]], ['upsert' => true]);

        return $result->getUpsertedCount() === 1;
    }

    public function finish(string $id, string $status): void
    {
        DB::connection('mongodb')->getDatabase()->qualification_email_alerts->updateOne(['_id' => $id],
            ['$set' => ['status' => $status, 'updated_at' => now('UTC')->toIso8601String()]]);
    }
}
