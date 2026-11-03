<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\AlertStakeEvidence;
use App\Infrastructure\Notifications\AlertStakePlanner;
use Mockery;
use Tests\TestCase;

class AlertStakePlannerTest extends TestCase
{
    private function inputs(): array
    {
        $fixture = ['provider_match_id' => 'vf:1', 'home_team_uid' => 'h', 'away_team_uid' => 'a', 'home_team' => 'Home', 'away_team' => 'Away',
            'matchday' => 6, 'scheduled_start_at' => '2026-11-03T10:03:00+00:00', 'status' => 'scheduled_or_unconfirmed'];
        $candidate = ['strategy_id' => 'champion-next-win', 'team_uid' => 'h'];
        $price = ['provider_match_id' => 'vf:1', 'pre_match_verified' => true, 'captured_at' => '2026-11-03T09:59:50+00:00',
            'stored_at' => '2026-11-03T09:59:51+00:00', 'capture_id' => 'synthetic-price', 'prices' => ['H' => '2.20']];

        return [$candidate, ['fixture' => $fixture, 'price' => $price], ['capital' => 100000, 'next_win_attempts' => 2, 'allowance' => 1],
            [[...$fixture, 'estimated_odds_scaled' => 20000], [...$fixture, 'provider_match_id' => 'vf:2', 'matchday' => 7,
                'scheduled_start_at' => '2026-11-03T10:06:40+00:00', 'estimated_odds_scaled' => 20000]]];
    }

    public function test_verified_current_price_generates_auditable_plan_and_alert_stakes(): void
    {
        [$candidate, $fact, $settings, $fixtures] = $this->inputs();
        $evidence = Mockery::mock(AlertStakeEvidence::class);
        $evidence->shouldReceive('window')->with($candidate, 2, '2026-11-03T10:00:00+00:00')->andReturn($fixtures);
        $planner = new AlertStakePlanner($evidence);
        $plan = $planner->make($candidate, $fact, $settings, '2026-11-03T10:00:00+00:00');
        $this->assertSame('estimated', $plan['status']);
        $details = $planner->details($plan);
        $this->assertStringContainsString('₦100,000.00', $details['Run capital']);
        $this->assertStringContainsString('stake ₦', $details['Attempt 1']);
        $this->assertStringContainsString('current quote 2.20', $details['Attempt 1']);
        $this->assertStringContainsString('later actual odds unknown', $details['Attempt 2']);
        $this->assertSame('synthetic-price', $plan['first_quote']['price_capture_id']);
        $fact['price']['pre_match_verified'] = false;
        $this->assertSame('unverified_price', $planner->make($candidate, $fact, $settings, '2026-11-03T10:00:00+00:00')['status']);
        $fact['price']['pre_match_verified'] = true;
        $fact['price']['captured_at'] = '2026-11-03T09:50:00+00:00';
        $this->assertSame('missing_or_stale_current_price', $planner->make($candidate, $fact, $settings, '2026-11-03T10:00:00+00:00')['status']);
    }
}
