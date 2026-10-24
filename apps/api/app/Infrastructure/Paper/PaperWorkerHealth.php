<?php

namespace App\Infrastructure\Paper;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaperWorkerHealth
{
    public function record(string $phase, ?string $result = null): void
    {
        $now = now('UTC');
        DB::connection('mongodb')->getDatabase()->paper_worker_states->updateOne(['_id' => 'main'], ['$set' => [
            'phase' => $phase, 'observed_at' => $now->toIso8601String(), 'last_result' => $result,
            'next_check_at' => $phase === 'checking' ? null : $now->addSeconds(15)->toIso8601String(),
        ]], ['upsert' => true]);
    }

    public function view(): array
    {
        try {
            $row = DB::connection('mongodb')->getDatabase()->paper_worker_states->findOne(['_id' => 'main'],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]);

            return $this->summarize($row, now('UTC')->toIso8601String());
        } catch (Throwable) {
            return ['status' => 'unavailable', 'observed_at' => null, 'next_check_at' => null, 'last_result' => null];
        }
    }

    public function summarize(?array $row, string $now): array
    {
        $status = 'not_observed';
        if ($row !== null) {
            $age = CarbonImmutable::parse($row['observed_at'])->diffInSeconds(CarbonImmutable::parse($now), false);
            $status = $age < 0 ? 'clock_unknown' : ($age > 60 ? 'not_recent' : ($row['phase'] === 'retrying' ? 'retrying' : 'active'));
        }

        return ['status' => $status, 'observed_at' => $row['observed_at'] ?? null,
            'next_check_at' => $row['next_check_at'] ?? null, 'last_result' => $row['last_result'] ?? null];
    }
}
