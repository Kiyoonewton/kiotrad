<?php

namespace Tests\Feature;

use App\Domain\Research\PayoutCarryPlan;
use App\Domain\Research\SharedCapitalReplay;
use Tests\TestCase;

class PayoutCarryPlanTest extends TestCase
{
    private function fixtures(): array
    {
        $rows = [];
        foreach ([15000, 18000, 25000, 30000, 20000] as $i => $odds) {
            $rows[] = ['matchday' => $i + 1, 'historical_odds_scaled' => $odds,
                'actual_odds_scaled' => [16000, 19000, 27000, 32000, 21000][$i],
                'scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4),
                'result_confirmed_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4 + 3), 'won' => false];
        }

        return $rows;
    }

    public function test_agreed_five_attempt_table_is_calculated_per_run_for_both_allowances(): void
    {
        $fixtures = $this->fixtures();
        foreach ([1 => [50667, 2999400, [101400, 266200, 371000, 526200, 1734600]],
            2 => [25333, 1499700, [50700, 133100, 185500, 263100, 867300]]] as $multiple => [$profit, $total, $stakes]) {
            $result = (new PayoutCarryPlan)->calculate($fixtures, 3000000, $multiple);
            $this->assertSame('estimated', $result['status']);
            $this->assertSame(592100, $result['baseline']['total_minor']);
            $this->assertSame([20000, 52500, 73200, 103900, 342500], array_column($result['baseline']['attempts'], 'stake_minor'));
            $this->assertSame($profit, $result['plan']['profit_per_attempt_minor']);
            $this->assertSame($total, $result['plan']['total_minor']);
            $this->assertSame($stakes, array_column($result['plan']['attempts'], 'stake_minor'));
            $this->assertLessThanOrEqual(3000000, $result['allowance_minor']);
            $this->assertFalse($result['executable_at_entry']);
        }
        $four = (new PayoutCarryPlan)->calculate(array_slice($fixtures, 0, 4), 3000000);
        $this->assertSame(249600, $four['baseline']['total_minor']);
        $fixtures[0]['actual_odds_scaled'] = 17000;
        $this->assertNotSame(592100, (new PayoutCarryPlan)->calculate($fixtures, 3000000)['baseline']['total_minor']);
    }

    public function test_minimum_stake_and_rounding_never_overdraw_the_plan_budget(): void
    {
        foreach ([10000, 100000, 3000000, 3000100] as $capital) {
            foreach ([1, 2] as $multiple) {
                $result = (new PayoutCarryPlan)->calculate($this->fixtures(), $capital, $multiple);
                if ($result['status'] === 'estimated') {
                    $this->assertLessThanOrEqual($capital, $result['allowance_minor']);
                    foreach ($result['plan']['attempts'] as $row) {
                        $this->assertGreaterThanOrEqual(10000, $row['stake_minor']);
                        $this->assertSame(0, $row['stake_minor'] % 100);
                    }
                } else {
                    $this->assertSame('insufficient_allowance', $result['status']);
                }
            }
        }
    }

    public function test_replay_stops_on_a_win_but_keeps_the_full_window_baseline_and_pinned_stakes(): void
    {
        $fixtures = $this->fixtures();
        $fixtures[1]['won'] = true;
        $result = (new SharedCapitalReplay)->calculate([['id' => 'synthetic', 'fixtures' => $fixtures]],
            3000000, '2026-10-21T00:00:00Z', 201, 5, 1, 'fixed', 0, 'payout_carry_v1');
        $run = $result['runs'][0];
        $this->assertSame('win', $run['status']);
        $this->assertCount(2, $run['attempts']);
        $this->assertSame(592100, $run['estimate']['baseline']['total_minor']);
        $this->assertSame(367600, $run['total_staked_minor']);
        $this->assertSame(505780, $run['gross_payout_minor']);
        $this->assertSame(138180, $run['net_minor']);
        $this->assertSame(3138180, $result['balance_minor']);
        $this->assertTrue($result['retrospective_odds_sizing']);
        $fixtures[4]['actual_odds_scaled'] = null; // Even after an early win, do not invent the sizing baseline.
        $missing = (new SharedCapitalReplay)->calculate([['id' => 'synthetic', 'fixtures' => $fixtures]],
            3000000, '2026-10-21T00:00:00Z', 201, 5, 1, 'fixed', 0, 'payout_carry_v1');
        $this->assertSame('incomplete_retrospective_odds', $missing['runs'][0]['status']);
        $this->assertSame(3000000, $missing['balance_minor']);
        $this->assertNull($missing['active_run_id']);
    }
}
