<?php

namespace App\Infrastructure\Provider;

class SharedSimulationHistory
{
    public function __construct(private DashboardSnapshot $snapshot) {}

    public function apply(array $page): array
    {
        $view = $this->snapshot->read()['shared_simulation'] ?? [];
        $available = ($view['status'] ?? null) === 'available';
        $runs = [];
        foreach ($view['runs'] ?? [] as $run) {
            $runs[$run['id']] = $run;
        }
        foreach ($page['rows'] as &$row) {
            $id = implode(':', [$row['competition'] ?? '', $row['season_id'] ?? '', $row['strategy_id'] ?? '']);
            // Never label an independent fixed-target replay as a shared run.
            $row['simulation'] = null;
            $row['shared_run'] = $available ? ($runs[$id] ?? null) : null;
            $row['shared_calculation_status'] = $available ? 'available' : 'refreshing';
        }
        unset($row);

        return [...$page, 'calculation_mode' => 'shared_capital_simulation',
            'shared_calculation_status' => $available ? 'available' : 'refreshing'];
    }
}
