<?php

namespace App\Domain\Research;

use DateTimeImmutable;

final class SharedTimingAudit
{
    public function inspect(array $runs): array
    {
        $blockers = [];
        foreach ($runs as $run) {
            if (isset($run['blocked_by'])) {
                $blockers[$run['blocked_by']][] = $run['id'];
            }
        }
        $timing = [];
        foreach ($runs as $run) {
            $fixtures = $run['fixtures'] ?? [];
            $late = [];
            foreach ($fixtures as $i => $fixture) {
                $confirmed = $fixture['result_confirmed_at'] ?? null;
                $next = $fixtures[$i + 1]['scheduled_start_at'] ?? null;
                if ($confirmed !== null && $next !== null && new DateTimeImmutable($confirmed) >= new DateTimeImmutable($next)) {
                    $late[] = ['matchday' => $fixture['matchday'] ?? null,
                        'scheduled_start_at' => $fixture['scheduled_start_at'],
                        'recorded_confirmation_at' => $confirmed, 'next_scheduled_start_at' => $next];
                }
            }
            $end = $run['result_observed_at'] ?? $run['released_at'] ?? $run['observed_window']['ended_at'] ?? null;
            $hours = $end === null ? null : (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($run['entry_at']))->getTimestamp();
            $timing[] = ['id' => $run['id'], 'status' => $run['status'],
                'entry_at' => $run['entry_at'], 'recorded_confirmation_at' => $end,
                'capture_span_hours' => $hours === null ? null : round($hours / 3600, 3),
                'confirmation_crosses_scheduled_attempts' => $late,
                'blocks_runs' => $blockers[$run['id']] ?? [],
                'blocked_by' => $run['blocked_by'] ?? null];
        }
        usort($timing, fn ($a, $b) => ($b['capture_span_hours'] ?? -1) <=> ($a['capture_span_hours'] ?? -1));

        return ['timing_basis' => 'capture_times_are_not_verified_event_finish_times',
            'actual_overlap_verified' => false, 'runs' => $timing];
    }
}
