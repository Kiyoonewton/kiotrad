<?php

namespace Tests\Feature;

use App\Domain\Alerts\EntryStakePlan;
use Tests\TestCase;

class EntryStakePlanTest extends TestCase
{
    private function fixtures(int $count = 5): array
    {
        $odds = [15000, 18000, 25000, 30000, 20000, 18000];

        return array_map(fn ($i) => ['matchday' => $i + 6, 'estimated_odds_scaled' => $odds[$i],
            ...($i === 0 ? ['current_odds_scaled' => 16000] : [])], range(0, $count - 1));
    }

    public function test_entry_plan_baseline_uses_only_first_current_price_then_estimates(): void
    {
        $result = (new EntryStakePlan)->calculate($this->fixtures(), 10000000);
        $this->assertSame('estimated', $result['status']);
        $this->assertSame([20000, 52500, 69700, 92200, 286600], array_column($result['baseline']['attempts'], 'stake_minor'));
        $this->assertSame(521000, $result['baseline']['total_minor']);
        $this->assertSame('current_quote', $result['plan']['attempts'][0]['payout_basis']);
        $this->assertSame('estimate', $result['plan']['attempts'][1]['payout_basis']);
        $future = $this->fixtures();
        $future[1]['actual_odds_scaled'] = 999999;
        $future[1]['won'] = true;
        $this->assertSame($result, (new EntryStakePlan)->calculate($future, 10000000));
    }

    public function test_configured_capital_allowance_and_attempts_determine_budget_without_overrun(): void
    {
        foreach ([3000000, 5000000, 10000000] as $capital) {
            foreach ([1, 2] as $allowance) {
                foreach ([4, 5, 6] as $count) {
                    $result = (new EntryStakePlan)->calculate($this->fixtures($count), $capital, $allowance);
                    $this->assertSame('estimated', $result['status']);
                    $this->assertCount($count, $result['plan']['attempts']);
                    $this->assertLessThanOrEqual($capital, $result['allowance_minor']);
                    $this->assertSame($result['plan']['total_minor'] * $allowance, $result['allowance_minor']);
                    $spent = $previous = 0;
                    foreach ($result['plan']['attempts'] as $row) {
                        $expected = max(10000, (int) ceil((($previous + $result['profit_target_minor']) * 10000) / (($row['estimated_odds_scaled'] - 10000) * 100)) * 100);
                        $this->assertSame($expected, $row['stake_minor']);
                        $spent += $expected;
                        $previous = intdiv($expected * $row['payout_odds_scaled'], 10000);
                        $this->assertSame($previous - $spent, $row['net_if_won_minor']);
                    }
                    $this->assertSame($spent, $result['plan']['total_minor']);
                }
            }
        }
        $small = (new EntryStakePlan)->calculate($this->fixtures(), 5000000);
        $large = (new EntryStakePlan)->calculate($this->fixtures(), 10000000);
        $this->assertGreaterThan($small['plan']['attempts'][0]['stake_minor'], $large['plan']['attempts'][0]['stake_minor']);
    }

    public function test_missing_or_below_floor_price_and_insufficient_capital_do_not_produce_stakes(): void
    {
        $fixtures = $this->fixtures();
        unset($fixtures[0]['current_odds_scaled']);
        $this->assertSame('missing_verified_current_price', (new EntryStakePlan)->calculate($fixtures, 10000000)['status']);
        $fixtures[0]['current_odds_scaled'] = 14000;
        $this->assertSame('current_price_below_estimate', (new EntryStakePlan)->calculate($fixtures, 10000000)['status']);
        $this->assertSame('insufficient_budget', (new EntryStakePlan)->calculate($this->fixtures(), 10000)['status']);
    }
}
