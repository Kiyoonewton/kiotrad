<?php

namespace Tests\Feature;

use App\Domain\Paper\PaperLedger;
use App\Domain\Paper\WinQuote;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Paper\PaperRunner;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\TestCase;

class PaperLedgerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private string $now = '2026-10-24T10:00:00+00:00';

    private function candidate(string $league = 'virtual-football'): array
    {
        return ['competition' => $league, 'season_id' => '200', 'strategy_id' => 'champion-next-win', 'version' => '0.2.0-observation',
            'status' => 'watching_fixture', 'team' => 'Champion', 'team_uid' => '1', 'attempt' => 1,
            'trigger_day' => 4, 'evidence_ready_at' => '2026-10-24T09:59:00+00:00', 'evaluated_at' => $this->now,
            'next_match' => ['matchday' => 5]];
    }

    private function fact(int $day = 5, string $odds = '1.5'): array
    {
        return ['fixture' => ['provider_match_id' => 'vf:match:'.$day, 'matchday' => $day,
            'home_team_uid' => '1', 'away_team_uid' => '2', 'status' => 'scheduled_or_unconfirmed', 'result' => null,
            'scheduled_start_at' => '2026-10-24T10:01:00+00:00'], 'observed_at' => $this->now, 'capture_id' => 'fixtures',
            'price' => ['provider_match_id' => 'vf:match:'.$day, 'pre_match_verified' => true,
                'captured_at' => '2026-10-24T09:59:50+00:00', 'capture_id' => 'price', 'stored_at' => $this->now,
                'prices' => ['H' => $odds, 'D' => '3.0', 'A' => '4.0']]];
    }

    private function initial(): array
    {
        return app(PaperLedger::class)->open(547100, 1094200, $this->now);
    }

    public function test_quote_recovers_prior_losses_and_attempt_profit_in_whole_naira(): void
    {
        $fact = $this->fact();
        $quote = app(WinQuote::class)->make($fact['fixture'], $fact['price'], '1', 20000, 2, 547100, $this->now);
        $this->assertSame(80000, $quote['stake_minor']);
        $this->assertSame(120000, $quote['potential_payout_minor']);
        $this->assertSame(20000, $quote['potential_payout_minor'] - $quote['stake_minor'] - 20000);
    }

    public function test_first_reservation_and_retries_never_double_spend(): void
    {
        $ledger = app(PaperLedger::class);
        $facts = ['virtual-football:200:1:5' => $this->fact()];
        $next = $ledger->advance($this->initial(), [$this->candidate()], $facts, $this->now);
        $this->assertSame(527100, $next['cash_minor']);
        $this->assertSame(20000, $next['committed_minor']);
        $this->assertSame($next, $ledger->advance($next, [$this->candidate()], $facts, $this->now));
        $this->assertCount(1, $next['decisions']);
    }

    public function test_winning_settlement_credits_once_and_preserves_the_original_decision(): void
    {
        $ledger = app(PaperLedger::class);
        $fact = $this->fact();
        $pending = $ledger->advance($this->initial(), [$this->candidate()], ['virtual-football:200:1:5' => $fact], $this->now);
        $fact['fixture']['status'] = 'settled';
        $fact['fixture']['result'] = 'H';
        $fact['observed_at'] = '2026-10-24T10:04:00+00:00';
        $closed = $ledger->advance($pending, [], ['virtual-football:200:1:5' => $fact], '2026-10-24T10:04:01+00:00');
        $this->assertSame(557100, $closed['cash_minor']);
        $this->assertSame(0, $closed['committed_minor']);
        $this->assertSame(10000, $closed['runs'][0]['net_minor']);
        $this->assertSame('win', $closed['runs'][0]['status']);
        $this->assertSame($pending['decisions'], $closed['decisions']);
        $this->assertSame($closed, $ledger->advance($closed, [$this->candidate()], [], '2026-10-24T10:04:01+00:00'));
    }

    public function test_draw_loses_then_next_quote_recovers_the_real_paper_loss(): void
    {
        $ledger = app(PaperLedger::class);
        $fact = $this->fact();
        $state = $ledger->advance($this->initial(), [$this->candidate()], ['virtual-football:200:1:5' => $fact], $this->now);
        $fact['fixture']['status'] = 'settled';
        $fact['fixture']['result'] = 'D';
        $fact['observed_at'] = '2026-10-24T10:04:00+00:00';
        $state = $ledger->advance($state, [], ['virtual-football:200:1:5' => $fact], $fact['observed_at']);
        $next = $this->fact(6);
        $next['fixture']['scheduled_start_at'] = '2026-10-24T10:05:00+00:00';
        $state = $ledger->advance($state, [], ['virtual-football:200:1:6' => $next], '2026-10-24T10:04:01+00:00');
        $this->assertSame(80000, $state['committed_minor']);
        $this->assertSame(447100, $state['cash_minor']);
        $this->assertSame(20000, $state['active_run']['losses_minor']);
    }

    public function test_overlapping_league_run_is_skipped_without_a_stake(): void
    {
        $ledger = app(PaperLedger::class);
        $state = $ledger->advance($this->initial(), [$this->candidate(), $this->candidate('virtual-euro')],
            ['virtual-football:200:1:5' => $this->fact()], $this->now);
        $this->assertSame('overlap_skipped', $state['runs'][0]['status']);
        $this->assertSame('virtual-euro', $state['runs'][0]['competition']);
        $this->assertCount(1, $state['decisions']);
    }

    public function test_missed_initial_attempt_does_not_start_a_hindsight_recovery(): void
    {
        $candidate = [...$this->candidate(), 'attempt' => 2];
        $state = app(PaperLedger::class)->advance($this->initial(), [$candidate], [], $this->now);
        $this->assertSame('missed_first_entry', $state['runs'][0]['status']);
        $this->assertSame(547100, $state['cash_minor']);
        $this->assertSame([], $state['decisions']);
    }

    public function test_unconfirmed_results_keep_funds_reserved(): void
    {
        $ledger = app(PaperLedger::class);
        $fact = $this->fact();
        $state = $ledger->advance($this->initial(), [$this->candidate()], ['virtual-football:200:1:5' => $fact], $this->now);
        $fact['fixture']['status'] = 'unavailable';
        $this->assertSame($state, $ledger->advance($state, [], ['virtual-football:200:1:5' => $fact], '2026-10-24T10:04:01+00:00'));
    }

    public function test_capital_limit_and_closed_entry_do_not_debit_funds(): void
    {
        $ledger = app(PaperLedger::class);
        foreach ([['cash_minor' => 10000], []] as $change) {
            $state = $ledger->advance([...$this->initial(), ...$change], [$this->candidate()], ['virtual-football:200:1:5' => $this->fact()], $change ? $this->now : '2026-10-24T10:02:00+00:00');
            $this->assertNull($state['active_run']);
            $this->assertSame([], $state['decisions']);
        }
    }

    public function test_stale_and_future_prices_are_rejected(): void
    {
        foreach (['2026-10-24T09:54:59+00:00', '2026-10-24T10:00:01+00:00'] as $time) {
            $fact = $this->fact();
            $fact['price']['captured_at'] = $time;
            try {
                app(WinQuote::class)->make($fact['fixture'], $fact['price'], '1', 0, 1, 547100, $this->now);
                $this->fail('Invalid price was accepted.');
            } catch (DomainException $exception) {
                $this->assertContains($exception->getMessage(), ['stale_price', 'unverified_price']);
            }
        }
    }

    public function test_four_losses_close_the_run_with_the_exact_cumulative_cost(): void
    {
        $ledger = app(PaperLedger::class);
        $state = $this->initial();
        for ($i = 0; $i < 4; $i++) {
            $time = CarbonImmutable::parse($this->now)->addMinutes($i * 4);
            $fact = $this->fact(5 + $i, '2.0');
            $fact['fixture']['scheduled_start_at'] = $time->addMinute()->toIso8601String();
            $fact['price']['captured_at'] = $time->subSecond()->toIso8601String();
            $fact['price']['stored_at'] = $time->toIso8601String();
            $key = 'virtual-football:200:1:'.(5 + $i);
            $state = $ledger->advance($state, $i === 0 ? [$this->candidate()] : [], [$key => $fact], $time->toIso8601String());
            $fact['fixture']['status'] = 'settled';
            $fact['fixture']['result'] = 'A';
            $fact['observed_at'] = $time->addMinutes(3)->toIso8601String();
            $state = $ledger->advance($state, [], [$key => $fact], $fact['observed_at']);
        }
        $this->assertNull($state['active_run']);
        $this->assertSame('four_losses', $state['runs'][0]['status']);
        $this->assertSame(-260000, $state['runs'][0]['net_minor']);
        $this->assertSame(287100, $state['cash_minor']);
        $this->assertCount(4, $state['decisions']);
    }

    public function test_revision_conflict_does_not_retry_using_a_backdated_decision(): void
    {
        CarbonImmutable::setTestNow($this->now);
        $store = $this->mock(PaperStore::class);
        $store->shouldReceive('read')->once()->andReturn($this->initial());
        $this->mock(ChampionMonitoring::class)->shouldReceive('all')->once()->andReturn([$this->candidate()]);
        $fact = $this->fact();
        $fact['fixture']['scheduled_start_at'] = now('UTC')->addMinutes(10)->toIso8601String();
        $fact['price']['captured_at'] = now('UTC')->subSecond()->toIso8601String();
        $this->mock(PaperMarket::class)->shouldReceive('facts')->once()->andReturn(['virtual-football:200:1:5' => $fact]);
        $store->shouldReceive('replace')->once()->andReturn(false);
        $this->assertSame('revision_changed', app(PaperRunner::class)->tick());
    }

    public function test_unconfigured_worker_does_not_fetch_markets_or_choose_capital(): void
    {
        $this->mock(PaperStore::class)->shouldReceive('read')->once()->andReturnNull();
        $this->mock(ChampionMonitoring::class)->shouldNotReceive('all');
        $this->mock(PaperMarket::class)->shouldNotReceive('facts');
        $this->assertSame('not_configured', app(PaperRunner::class)->tick());
    }
}
