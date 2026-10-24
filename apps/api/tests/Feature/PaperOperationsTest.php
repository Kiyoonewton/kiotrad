<?php

namespace Tests\Feature;

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\PaperStatus;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Paper\PaperWorkerHealth;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PaperOperationsTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_milestones_advance_in_fixed_increments_and_keep_achievements_after_drawdown(): void
    {
        $state = app(PaperLedger::class)->open(700000, 1400000, '2026-10-24T10:00:00+00:00');
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturn($state);
        $view = app(PaperStatus::class)->overview();
        $this->assertSame(1400000, $view['bankroll']['next_milestone_minor']);
        $state['cash_minor'] = 1300000;
        $state['capital_history'][] = ['at' => '2026-10-24T11:00:00+00:00', 'balance_minor' => 2800000];
        $state['capital_history'][] = ['at' => '2026-10-24T11:10:00+00:00', 'balance_minor' => 1300000];
        $store->shouldReceive('read')->once()->andReturn($state);
        $view = app(PaperStatus::class)->overview();
        $this->assertSame(4200000, $view['bankroll']['next_milestone_minor']);
        $this->assertSame(2, $view['bankroll']['milestones_reached']);
        $this->assertSame(1300000, $view['bankroll']['balance_minor']);
        $this->assertSame(1400000, $view['bankroll']['target_balance_minor']);
    }

    public function test_reaching_the_milestone_does_not_stop_the_next_eligible_run(): void
    {
        $ledger = app(PaperLedger::class);
        $now = '2026-10-24T10:00:00+00:00';
        $state = $ledger->open(700000, 1400000, $now);
        $state['cash_minor'] = 1400000;
        $state['runs'][] = ['id' => 'prior-completed-run', 'net_minor' => 700000];
        $state['capital_history'][] = ['at' => $now, 'balance_minor' => 1400000];
        $candidate = ['competition' => 'virtual-football', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
            'version' => '0.2.0-observation', 'status' => 'watching_fixture', 'team_uid' => '1', 'team' => 'Synthetic',
            'attempt' => 1, 'next_match' => ['matchday' => 5], 'evidence_ready_at' => $now];
        $fact = ['observed_at' => $now, 'capture_id' => 'fixture', 'fixture' => ['provider_match_id' => 'vf:match:5005',
            'home_team_uid' => '1', 'away_team_uid' => '2', 'scheduled_start_at' => '2026-10-24T10:01:00+00:00',
            'status' => 'scheduled_or_unconfirmed'], 'price' => ['provider_match_id' => 'vf:match:5005',
                'pre_match_verified' => true, 'captured_at' => $now, 'capture_id' => 'price', 'prices' => ['H' => '1.5']]];
        $next = $ledger->advance($state, [$candidate], ['virtual-football:200:1:5' => $fact], $now);
        $this->assertSame(20000, $next['committed_minor']);
        $this->assertSame('awaiting_result', $next['active_run']['status']);
        $this->assertSame(1400000, $next['target_balance_minor']);
    }

    public function test_worker_health_distinguishes_missing_recent_failed_and_stale_heartbeats(): void
    {
        $health = new PaperWorkerHealth;
        $now = '2026-10-24T10:00:00+00:00';
        $this->assertSame('not_observed', $health->summarize(null, $now)['status']);
        $record = ['observed_at' => $now, 'phase' => 'completed', 'last_result' => 'waiting'];
        $this->assertSame('active', $health->summarize($record, $now)['status']);
        $this->assertSame('retrying', $health->summarize([...$record, 'phase' => 'retrying'], $now)['status']);
        $this->assertSame('not_recent', $health->summarize($record, '2026-10-24T10:01:01+00:00')['status']);
        $this->assertSame('clock_unknown', $health->summarize($record, '2026-10-24T09:59:59+00:00')['status']);
    }

    public function test_status_command_does_not_create_funds_or_execute_decisions(): void
    {
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturnNull();
        $store->shouldNotReceive('create');
        $store->shouldNotReceive('replace');
        $health = $this->mock(PaperWorkerHealth::class);
        $health->shouldReceive('view')->once()->andReturn(['status' => 'not_observed', 'observed_at' => null, 'last_result' => null]);
        $health->shouldNotReceive('record');
        $this->mock(ChampionMonitoring::class)->shouldReceive('all')->once()->andReturn([]);
        $this->artisan('paper:status')->expectsOutput('Read-only status. No paper entries or settlements were executed.')->assertSuccessful();
    }

    public function test_worker_failures_record_retrying_instead_of_a_successful_cycle(): void
    {
        $this->mock(PaperStore::class)->shouldReceive('read')->once()->andThrow(new \RuntimeException('unavailable'));
        $health = $this->mock(PaperWorkerHealth::class);
        $health->shouldReceive('record')->with('checking')->once()->ordered();
        $health->shouldReceive('record')->with('retrying', 'cycle_failed')->once()->ordered();
        $this->artisan('paper:watch', ['--once' => true])->assertFailed();
    }

    public function test_watch_once_records_a_successful_unconfigured_cycle_without_creating_a_bankroll(): void
    {
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturnNull();
        $store->shouldNotReceive('create');
        $health = $this->mock(PaperWorkerHealth::class);
        $health->shouldReceive('record')->with('checking')->once()->ordered();
        $health->shouldReceive('record')->with('completed', 'not_configured')->once()->ordered();
        $this->artisan('paper:watch', ['--once' => true])->assertSuccessful();
    }
}
