<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\NotificationOutbox;
use App\Infrastructure\Notifications\PaperNotifications;
use App\Infrastructure\Paper\PaperStore;
use Mockery;
use Tests\TestCase;

class PaperNotificationsTest extends TestCase
{
    public function test_old_paper_history_is_suppressed_but_new_stakes_and_actual_settlement_are_reported(): void
    {
        $attempt = ['attempt' => 1, 'matchday' => 5, 'provider_match_id' => 'synthetic:5', 'outcome' => 'H',
            'odds' => '1.5', 'stake_minor' => 20000, 'potential_payout_minor' => 30000, 'payout_minor' => 30000,
            'target_profit_minor' => 10000, 'scheduled_start_at' => '2026-10-24T10:01:00+00:00',
            'decided_at' => '2026-10-24T10:00:30+00:00'];
        $run = ['id' => 'synthetic:new', 'competition' => 'virtual-football', 'season_id' => '200',
            'strategy_id' => 'champion-next-win', 'team' => 'Synthetic', 'attempts' => [$attempt],
            'net_minor' => 10000, 'status' => 'win', 'ended_at' => '2026-10-24T10:05:00+00:00'];
        $old = [...$run, 'id' => 'synthetic:old', 'ended_at' => '2026-10-23T10:05:00+00:00',
            'attempts' => [[...$attempt, 'decided_at' => '2026-10-23T10:00:30+00:00']]];
        $store = Mockery::mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturn(['runs' => [$old, $run], 'active_run' => null]);
        $outbox = Mockery::mock(NotificationOutbox::class);
        $outbox->shouldReceive('enqueue')->once()->withArgs(fn ($key, $type, $details) => $type === 'stake'
            && $key === 'stake:synthetic:new:1' && $details['Paper stake'] === '₦200.00');
        $outbox->shouldReceive('enqueue')->once()->withArgs(fn ($key, $type, $details) => $type === 'settled'
            && $details['Gross payouts'] === '₦300.00' && $details['Run net'] === '₦100.00');
        (new PaperNotifications($store))->scan($outbox, '2026-10-24T10:00:00+00:00');
    }
}
