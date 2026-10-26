<?php

namespace App\Domain\Research;

final class SharedTimingPresentation
{
    public function describe(array $runs, ?int $seconds = null): array
    {
        $late = [];
        $byId = array_column($runs, null, 'id');
        foreach ((new SharedTimingAudit)->inspect($runs)['runs'] as $row) {
            $warnings = $row['confirmation_crosses_scheduled_attempts'];
            $attemptedDays = array_column($byId[$row['id']]['attempts'] ?? [], 'matchday');
            if ($attemptedDays !== []) {
                $warnings = array_filter($warnings, fn ($warning) => in_array($warning['matchday'], $attemptedDays, true));
            }
            if ($warnings !== []) {
                $late[$row['id']] = true;
            }
        }
        $provisional = false;
        foreach ($runs as &$run) {
            $lastAttempt = empty($run['attempts']) ? null : $run['attempts'][array_key_last($run['attempts'])];
            $run['result_observed_at'] = $lastAttempt['result_confirmed_at'] ?? $run['observed_window']['result_observed_at']
                ?? $run['released_at'] ?? $run['observed_window']['ended_at'] ?? null;
            $run['simulation_settlement_at'] = $seconds === null ? null : ($run['released_at'] ?? null);
            $run['event_finish_at'] = null;
            $run['timing_warning'] = isset($late[$run['id']]) ? 'late_archive_confirmation' : null;
            if (isset($run['blocked_by'])) {
                $run['overlap_basis'] = $seconds !== null ? 'modeled_settlement_offset' : (isset($late[$run['blocked_by']])
                    ? 'unverified_late_archive_timing' : 'recorded_confirmation_upper_bound');
            }
            if (isset($run['estimate'], $late[$run['id']]) && ! str_starts_with($run['status'], 'skipped_')) {
                $provisional = true;
            }
        }
        unset($run);

        return ['runs' => $runs, 'timing_quality' => $seconds !== null ? 'modeled_settlement' : ($provisional ? 'provisional_capture_timing' : 'recorded_confirmation_upper_bound'),
            'event_finish_times_verified' => false];
    }
}
