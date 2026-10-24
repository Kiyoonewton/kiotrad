<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\NotificationOutbox;
use App\Infrastructure\Notifications\RecoveredSimulationNotifications;
use App\Infrastructure\Provider\ChampionMonitoring;
use Mockery;
use Tests\TestCase;

class RecoveredSimulationNotificationsTest extends TestCase
{
    public function test_recovered_update_is_labeled_historical_with_original_event_time_and_profit(): void
    {
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('all')->with(true)->once()->andReturn([[
            'recovered' => true, 'competition' => 'virtual-football', 'season_id' => '200',
            'strategy_id' => 'champion-next-win', 'version' => '0.3.0-event-simulation', 'team' => 'Synthetic',
            'trigger_day' => 4, 'attempt' => 1, 'status' => 'observed_win',
            'next_match' => ['matchday' => 5, 'scheduled_start_at' => '2026-10-20T10:00:00+00:00'],
            'simulation' => ['status' => 'win', 'total_staked_minor' => 20000, 'gross_payout_minor' => 30000, 'net_minor' => 10000],
        ]]);
        $outbox = Mockery::mock(NotificationOutbox::class);
        $outbox->shouldReceive('enqueue')->once()->withArgs(fn ($key, $type, $details) => $type === 'recovered_simulation'
            && $details['Original scheduled Lagos'] === '2026-10-20 11:00:00 WAT'
            && $details['Net simulated profit/loss'] === '₦100.00'
            && str_contains($details['Context'], 'not a new upcoming bet'));
        (new RecoveredSimulationNotifications($monitor))->scan($outbox);
    }
}
