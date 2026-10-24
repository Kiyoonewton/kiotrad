<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;

final class RecoveredSimulationNotifications
{
    public function __construct(private ChampionMonitoring $monitoring) {}

    public function scan(NotificationOutbox $outbox): void
    {
        foreach ($this->monitoring->all(true) as $row) {
            if (! ($row['recovered'] ?? false) || empty($row['trigger_day']) || empty($row['next_match'])) {
                continue;
            }
            $simulation = $row['simulation'] ?? null;
            $key = implode(':', ['recovered-simulation', $row['competition'], $row['season_id'], $row['strategy_id'],
                $row['version'], $row['attempt'] ?? 0, $row['status'], $simulation['status'] ?? 'unpriced']);
            $details = ['League' => $row['competition'], 'Season' => $row['season_id'], 'Strategy' => $row['strategy_id'],
                'Team' => $row['team'], 'Simulation status' => $row['status'], 'Attempt' => ($row['attempt'] ?? 0).' / 4',
                'Original matchday' => $row['next_match']['matchday'],
                'Original scheduled Lagos' => CarbonImmutable::parse($row['next_match']['scheduled_start_at'])->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s T'),
                'Context' => 'Recovered research simulation update — not a new upcoming bet or a recorded execution.'];
            if ($simulation) {
                $details += ['Calculation status' => $simulation['status'],
                    'Total simulated stake' => OpportunityNotifications::money($simulation['total_staked_minor']),
                    'Gross simulated payout' => OpportunityNotifications::money($simulation['gross_payout_minor']),
                    'Net simulated profit/loss' => $simulation['net_minor'] === null ? 'Incomplete calculation'
                        : OpportunityNotifications::money($simulation['net_minor'])];
            }
            $outbox->enqueue($key, 'recovered_simulation', $details);
        }
    }
}
