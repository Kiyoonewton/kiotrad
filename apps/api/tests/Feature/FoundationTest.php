<?php

namespace Tests\Feature;

use App\Infrastructure\Database\MongoReadiness;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\FeedStatus;
use RuntimeException;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_overview_does_not_invent_live_data_or_financial_results(): void
    {
        $this->mock(ChampionMonitoring::class)->shouldReceive('all')->once()->andReturn([]);
        $this->mock(FeedStatus::class)->shouldReceive('all')->once()->andReturn(
            array_map(fn ($c) => [...$c, 'feed_status' => 'not_connected'], config('kiotrad.competitions')),
        );
        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('mode', 'foundation')
            ->assertJsonPath('currency', 'NGN')
            ->assertJsonPath('display_timezone', 'Africa/Lagos')
            ->assertJsonPath('bankroll.balance_minor', null)
            ->assertJsonPath('active_run', null)
            ->assertJsonCount(3, 'competitions')
            ->assertJsonCount(2, 'strategies')
            ->assertJsonPath('competitions.0.feed_status', 'not_connected')
            ->assertJsonPath('strategies.0.monitoring_enabled', true)
            ->assertJsonPath('strategies.1.bankroll_enabled', false)
            ->assertJsonPath('strategies.0.rules.max_attempts', 4)
            ->assertJsonPath('capital_history', []);
    }

    public function test_liveness_does_not_require_database_access(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_readiness_returns_service_unavailable_without_leaking_driver_details(): void
    {
        $this->mock(MongoReadiness::class)->shouldReceive('check')->once()
            ->andThrow(new RuntimeException('mongodb://secret-user:secret-password@database'));

        $this->getJson('/api/v1/ready')->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'database' => 'unavailable']);
    }

    public function test_readiness_reports_a_successful_ping(): void
    {
        $this->mock(MongoReadiness::class)->shouldReceive('check')->once();

        $this->getJson('/api/v1/ready')->assertOk()
            ->assertExactJson(['status' => 'ready', 'database' => 'available']);
    }
}
