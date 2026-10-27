<?php

namespace Tests\Feature;

use App\Domain\Research\FixtureOddsBaseline;
use App\Domain\Research\RunBudgetEstimate;
use Tests\TestCase;

class FixtureOddsBaselineTest extends TestCase
{
    private function capture(string $home, string $away, string $homeOdds, string $id): array
    {
        return ['_id' => $id, 'matches' => [['provider_match_id' => 'match:'.$id, 'home_team_uid' => $home,
            'away_team_uid' => $away, 'home_team' => $home, 'away_team' => $away, 'scheduled_start_at' => '2026-10-01T00:00:00Z']],
            'odds_snapshots' => [['provider_match_id' => 'match:'.$id, 'prices' => ['H' => $homeOdds, 'D' => '3.5', 'A' => '4.2'],
                'captured_at' => '2026-10-02T00:00:00Z', 'pre_match_verified' => false]]];
    }

    public function test_minimum_is_exact_venue_specific_and_retains_source_evidence(): void
    {
        $rows = (new FixtureOddsBaseline)->fold([
            $this->capture('Chelsea', 'Liverpool', '1.57', 'a'),
            $this->capture('Chelsea', 'Liverpool', '1.43', 'b'),
            $this->capture('Liverpool', 'Chelsea', '1.20', 'c'),
        ], 'virtual-football', '100');
        $this->assertCount(2, $rows);
        $this->assertSame('1.43', $rows[0]['minima']['H']['odds']);
        $this->assertSame('b', $rows[0]['minima']['H']['capture_id']);
        $this->assertSame(14300, $rows[0]['minima']['H']['odds_scaled']);
        $this->assertFalse($rows[0]['minima']['H']['pre_match_verified']);
        $this->assertSame('1.20', $rows[1]['minima']['H']['odds']);
        $this->assertSame('3.5', $rows[0]['minima']['D']['odds']);
    }

    public function test_budget_uses_four_attempt_recovery_and_single_allowance(): void
    {
        $result = (new RunBudgetEstimate)->calculate([20000, 20000, 20000, 20000], 5000000);
        $plan = $result['plan'];
        $this->assertSame(192300, $plan['profit_per_attempt_minor']);
        $this->assertSame([192300, 576900, 1346100, 2884500], array_column($plan['attempts'], 'stake_minor'));
        $this->assertSame(4999800, $result['allowance_minor']);
        $this->assertLessThanOrEqual(5000000, $result['allowance_minor']);
        $this->assertSame(1, $result['allowance_multiplier']);
        $this->assertSame($plan['total_minor'], $result['allowance_minor']);
        $this->assertFalse($result['guaranteed_maximum']);
        $this->assertSame('insufficient_allowance', (new RunBudgetEstimate)->calculate([10001, 10001, 10001, 10001], 5000000)['status']);
    }

    public function test_minimum_ticket_and_whole_naira_rounding_are_included_in_budget(): void
    {
        $result = (new RunBudgetEstimate)->calculate([15000], 31400);
        $this->assertSame(15700, $result['plan']['profit_per_attempt_minor']);
        $this->assertSame(31400, $result['plan']['total_minor']);
        $this->assertSame('insufficient_allowance', (new RunBudgetEstimate)->calculate([20000], 9999)['status']);
    }
}
