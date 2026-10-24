<?php

namespace Tests\Feature;

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\PaperStatus;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Paper\PaperWorkerHealth;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\FeedStatus;
use RuntimeException;
use Tests\TestCase;

class PaperStatusTest extends TestCase
{
    public function test_reservations_are_not_counted_as_profit_or_as_available_cash(): void
    {
        $state = app(PaperLedger::class)->open(547100, 1094200, '2026-10-24T10:00:00+00:00');
        $state['cash_minor'] -= 20000;
        $state['committed_minor'] = 20000;
        $this->mock(PaperStore::class)->shouldReceive('read')->once()->andReturn($state);
        $view = app(PaperStatus::class)->overview();
        $this->assertSame(547100, $view['bankroll']['balance_minor']);
        $this->assertSame(527100, $view['bankroll']['available_minor']);
        $this->assertSame(0, $view['bankroll']['net_profit_minor']);
        $this->assertSame([], $view['recent_runs']);
    }

    public function test_unavailable_storage_does_not_appear_as_a_zero_balance(): void
    {
        $this->mock(PaperStore::class)->shouldReceive('read')->once()->andThrow(new RuntimeException('unavailable'));
        $view = app(PaperStatus::class)->overview();
        $this->assertSame('unavailable', $view['bankroll']['status']);
        $this->assertNull($view['bankroll']['balance_minor']);
    }

    public function test_overview_reads_paper_state_without_executing_decisions(): void
    {
        $state = app(PaperLedger::class)->open(547100, null, '2026-10-24T10:00:00+00:00');
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturn($state);
        $store->shouldNotReceive('replace');
        $store->shouldNotReceive('create');
        $this->mock(FeedStatus::class)->shouldReceive('all')->once()->andReturn([]);
        $this->mock(PaperWorkerHealth::class)->shouldReceive('view')->once()->andReturn(['status' => 'not_observed']);
        $this->mock(ChampionMonitoring::class)->shouldReceive('all')->once()->andReturn([]);
        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('mode', 'paper')
            ->assertJsonPath('bankroll.balance_minor', 547100)
            ->assertJsonPath('bankroll.net_profit_minor', 0)
            ->assertJsonPath('strategies.0.bankroll_enabled', true)
            ->assertJsonPath('active_run', null);
    }

    public function test_invalid_configuration_does_not_write_and_existing_portfolio_is_not_reset(): void
    {
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('create')->once()->andReturn(false);
        $this->artisan('paper:configure', ['capital' => '5,471'])->assertFailed();
        $this->artisan('paper:configure', ['capital' => '5471'])->expectsOutput('Paper bankroll already exists. No funds or history were changed.')->assertFailed();
    }
}
