<?php

namespace Tests\Feature;

use App\Domain\Research\SharedCapitalReplay;
use Tests\TestCase;

class UnfundedEntryTest extends TestCase
{
    public function test_zero_stake_missing_price_cannot_block_the_next_candidate(): void
    {
        $fixture = ['scheduled_start_at' => '2026-10-20T10:00:00Z', 'result_confirmed_at' => '2026-10-20T10:02:00Z',
            'historical_odds_scaled' => 20000, 'actual_odds_scaled' => null, 'won' => true];
        $priced = [...$fixture, 'actual_odds_scaled' => 20000];
        $view = (new SharedCapitalReplay)->calculate([
            ['id' => 'a', 'fixtures' => [$fixture]], ['id' => 'b', 'fixtures' => [$priced]],
        ], 5000000, '2026-10-21T00:00:00Z');
        $this->assertSame(['unfunded_entry', 'win'], array_column($view['runs'], 'status'));
        $this->assertFalse($view['runs'][0]['funded']);
        $this->assertSame('missing_actual_odds', $view['runs'][0]['entry_issue']);
        $this->assertSame(10000000, $view['balance_minor']);
        $this->assertNull($view['active_run_id']);
        $this->assertCount(1, $view['equity']);
    }

    public function test_confirmed_market_block_has_an_explicit_unfunded_reason(): void
    {
        $fixture = ['scheduled_start_at' => '2026-10-20T10:00:00Z', 'result_confirmed_at' => '2026-10-20T10:02:00Z',
            'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => true, 'market_blocked' => true];
        $view = (new SharedCapitalReplay)->calculate([['id' => 'blocked', 'fixtures' => [$fixture]]], 5000000, '2026-10-21T00:00:00Z');
        $this->assertSame('market_blocked', $view['runs'][0]['entry_issue']);
        $this->assertSame(0, $view['reserved_allowance_minor']);
        $this->assertNull($view['active_run_id']);
    }

    public function test_missing_later_price_keeps_actual_loss_unresolved_instead_of_erasing_it(): void
    {
        $a = ['scheduled_start_at' => '2026-10-20T10:00:00Z', 'result_confirmed_at' => '2026-10-20T10:02:00Z',
            'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => false];
        $b = [...$a, 'scheduled_start_at' => '2026-10-20T10:04:00Z', 'actual_odds_scaled' => null];
        $view = (new SharedCapitalReplay)->calculate([['id' => 'a', 'fixtures' => [$a, $b]]], 5000000, '2026-10-21T00:00:00Z');
        $this->assertSame('awaiting_price_or_market_resolution', $view['runs'][0]['status']);
        $this->assertGreaterThan(0, $view['active_staked_minor']);
        $this->assertSame('a', $view['active_run_id']);
        $this->assertNull($view['runs'][0]['net_minor']);
    }
}
