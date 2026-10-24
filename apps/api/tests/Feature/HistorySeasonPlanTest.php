<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\HistorySeasonPlan;
use Tests\TestCase;

class HistorySeasonPlanTest extends TestCase
{
    public function test_uses_provider_dates_not_numeric_gaps_and_selects_latest_available_history(): void
    {
        $seasons = [
            ['_id' => '900', 'start' => ['uts' => 100]],
            ['_id' => '200', 'start' => ['uts' => 200]],
            ['_id' => '350', 'start' => ['uts' => 300]],
            ['_id' => '350', 'start' => ['uts' => 300]],
            ['_id' => '400', 'start' => ['uts' => 400]],
            ['_id' => '500', 'start' => ['uts' => 500]],
            ['_id' => '600'],
        ];
        $plan = new HistorySeasonPlan;
        $this->assertSame(['200', '350'], $plan->previous($seasons, 400, 2));
        $this->assertSame(['900', '200', '350'], $plan->previous($seasons, 400, 81));
        $this->assertSame([], $plan->previous($seasons, 400, 0));
    }
}
