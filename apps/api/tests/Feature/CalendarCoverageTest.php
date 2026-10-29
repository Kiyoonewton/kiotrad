<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CalendarCoverage;
use Tests\TestCase;

class CalendarCoverageTest extends TestCase
{
    public function test_coverage_uses_lagos_dates_and_ignores_unknown_and_future_timing(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 29));
        $view = (new CalendarCoverage)->summarize([
            ['_id' => 'virtual-euro', 'fixtures' => 32, 'unknown' => 8,
                'starts' => [null, 'invalid', '2026-10-17T23:30:00Z', '2026-10-18T01:00:00Z', '2026-10-20T00:00:00Z', '2026-11-01T00:00:00Z']],
        ]);
        $this->assertSame(['2026-10-18', '2026-10-20'], $view['leagues'][0]['dates']);
        $this->assertSame('2026-10-17T23:30:00+00:00', $view['leagues'][0]['first_at']);
        $this->assertSame('2026-10-18T01:00:00+00:00', $view['leagues'][0]['days'][0]['last_at']);
        $this->assertSame('2026-10-17T23:30:00+00:00', $view['leagues'][0]['days'][0]['first_at']);
        $this->assertSame(8, $view['leagues'][0]['unknown_timing_fixtures']);
    }
}
