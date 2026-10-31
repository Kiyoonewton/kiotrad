<?php

namespace Tests\Feature;

use App\Domain\Research\SharedCapitalReplay;
use DomainException;
use Tests\TestCase;

class CapitalModeReplayTest extends TestCase
{
    private function runs(array $wins): array
    {
        $rows = [];
        foreach ($wins as $index => $won) {
            $rows[] = ['id' => 'run:'.$index, 'fixtures' => [[
                'scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', $index * 5),
                'result_confirmed_at' => sprintf('2026-10-20T10:%02d:00Z', $index * 5 + 2),
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => $won,
            ]]];
        }

        return $rows;
    }

    private function replay(array $wins, string $mode = 'compound', int $stop = 0): array
    {
        return (new SharedCapitalReplay)->calculate($this->runs($wins), 5000000, '2026-10-20T11:00:00Z', null, 1, 1, $mode, $stop);
    }

    public function test_fixed_sizing_keeps_gains_in_balance_but_does_not_compound_next_stake(): void
    {
        $fixed = $this->replay([true, true], 'fixed');
        $compound = $this->replay([true, true]);
        $this->assertSame(15000000, $fixed['balance_minor']);
        $this->assertSame(20000000, $compound['balance_minor']);
        $this->assertSame(5000000, $fixed['runs'][1]['sizing_capital_at_entry_minor']);
        $this->assertSame(10000000, $fixed['runs'][1]['capital_at_entry_minor']);
        $this->assertSame(10000000, $compound['runs'][1]['sizing_capital_at_entry_minor']);
        $this->assertSame($fixed['runs'][0]['estimate'], $fixed['runs'][1]['estimate']);
    }

    public function test_fixed_mode_never_resets_lost_money_or_funds_an_unaffordable_run(): void
    {
        $view = $this->replay([false, true], 'fixed');
        $this->assertSame(0, $view['balance_minor']);
        $this->assertSame('skipped_budget', $view['runs'][1]['status']);
        $this->assertSame('insufficient_allowance', $view['runs'][1]['skip_reason']);
        $this->assertCount(1, $view['equity']);
    }

    public function test_accumulated_gains_remain_available_to_absorb_a_loss(): void
    {
        $view = $this->replay([true, false, true], 'fixed');
        $this->assertSame(10000000, $view['balance_minor']);
        $this->assertCount(3, $view['equity']);
        $this->assertSame(5000000, $view['runs'][2]['sizing_capital_at_entry_minor']);
    }

    public function test_profit_stop_uses_net_gain_and_stops_at_completed_run_boundary_with_overshoot(): void
    {
        $view = $this->replay([true, true, true], 'fixed', 6000000);
        $this->assertCount(2, $view['runs']);
        $this->assertSame(15000000, $view['balance_minor']);
        $this->assertSame('profit_target_reached', $view['stop_reason']);
        $this->assertSame($view['equity'][1]['at'], $view['stopped_at']);
        $this->assertSame(6000000, $view['profit_stop_minor']);
        $compound = $this->replay([true, true], 'compound', 5000000);
        $this->assertCount(1, $compound['runs']);
    }

    public function test_unconfirmed_win_does_not_trigger_profit_stop(): void
    {
        $runs = $this->runs([true]);
        $runs[0]['fixtures'][0]['result_confirmed_at'] = '2026-10-20T12:00:00Z';
        $view = (new SharedCapitalReplay)->calculate($runs, 5000000, '2026-10-20T11:00:00Z', null, 1, 1, 'fixed', 100);
        $this->assertSame(5000000, $view['balance_minor']);
        $this->assertNull($view['stop_reason']);
        $this->assertSame('run:0', $view['active_run_id']);
    }

    public function test_no_target_preserves_default_replay_and_unknown_modes_are_rejected(): void
    {
        $view = $this->replay([true, true]);
        $this->assertNull($view['stop_reason']);
        $this->assertCount(2, $view['runs']);
        $this->expectException(DomainException::class);
        $this->replay([], 'unknown');
    }
}
