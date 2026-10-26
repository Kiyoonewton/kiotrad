<?php

namespace App\Domain\Research;

final class TimingSensitivityReplay
{
    public const PRIMARY_SECONDS = 201;

    public function calculate(array $candidates, int $capital, string $asOf): array
    {
        $scenarios = [];
        $primary = null;
        foreach ([201, 180, 220] as $seconds) {
            $view = (new SharedCapitalReplay)->calculate($candidates, $capital, $asOf, $seconds);
            $ids = array_column(array_values(array_filter($view['runs'],
                fn ($r) => isset($r['estimate']) && ! str_starts_with($r['status'], 'skipped_'))), 'id');
            if ($seconds === self::PRIMARY_SECONDS) {
                $primary = $view;
                $primaryIds = $ids;
            }
            $scenarios[] = ['settlement_seconds' => $seconds, 'balance_minor' => $view['balance_minor'],
                'completed_runs' => count($view['equity']), 'active_run_id' => $view['active_run_id'],
                'overlap_exclusions' => count(array_filter($view['runs'], fn ($r) => in_array($r['status'], ['skipped_overlap', 'skipped_simultaneous_entry'], true))),
                'allocation_changed_vs_primary' => $ids !== $primaryIds,
                'accepted_only_in_scenario' => array_values(array_diff($ids, $primaryIds)),
                'accepted_only_in_primary' => array_values(array_diff($primaryIds, $ids))];
        }

        return [...$primary, 'settlement_model' => ['version' => SettlementTimingModel::VERSION,
            'seconds' => self::PRIMARY_SECONDS, 'basis' => 'simulation_assumption_not_verified_event_finish'],
            'timing_sensitivity' => $scenarios,
            'timing_sensitive' => count(array_unique(array_column($scenarios, 'balance_minor'))) > 1
                || count(array_filter($scenarios, fn ($s) => $s['allocation_changed_vs_primary'])) > 0];
    }
}
