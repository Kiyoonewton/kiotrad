<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\SimulationHistory;
use Tests\TestCase;

class SimulationHistoryTest extends TestCase
{
    public function test_history_has_bounded_pagination_and_uses_lagos_event_dates(): void
    {
        $this->mock(SimulationHistory::class)->shouldReceive('page')->once()->with(2, 10, 'virtual-football',
            '2026-10-23T23:00:00+00:00', '2026-10-24T22:59:59+00:00')->andReturn([
                'schema_version' => '1', 'rows' => [], 'pagination' => ['page' => 2, 'total' => 20, 'last_page' => 2],
            ]);
        $this->getJson('/api/v1/simulations?page=2&competition=virtual-football&from=2026-10-24&to=2026-10-24')
            ->assertOk()->assertJsonPath('pagination.page', 2)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_pages_leagues_and_inverted_dates_are_rejected_before_source_reads(): void
    {
        $this->getJson('/api/v1/simulations?page=0&per_page=500&competition=unknown')->assertStatus(422);
        $this->getJson('/api/v1/simulations?from=2026-10-25&to=2026-10-24')->assertStatus(422);
    }
}
