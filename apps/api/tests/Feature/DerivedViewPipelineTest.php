<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\DerivedViewBackup;
use App\Infrastructure\Provider\PreparedResearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class DerivedViewPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cache.stores.redis' => ['driver' => 'array']]);
    }

    public function test_worker_prepares_simulator_even_when_overview_fails(): void
    {
        $this->mock(DashboardSnapshot::class)->shouldReceive('refresh')->once()->andReturn('source_unavailable');
        $this->mock(PreparedResearch::class)->shouldReceive('refresh')->once()->andReturn('prepared');
        $this->artisan('dashboard:watch', ['--once' => true])->assertFailed();
    }

    public function test_one_shot_preparation_does_not_claim_success_when_simulator_failed(): void
    {
        $this->mock(DashboardSnapshot::class)->shouldReceive('refresh')->once()->andReturn('refreshed');
        $this->mock(PreparedResearch::class)->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('failed'));
        $this->artisan('dashboard:watch', ['--once' => true])->assertFailed();
    }

    public function test_one_shot_succeeds_only_when_both_views_ready(): void
    {
        $this->mock(DashboardSnapshot::class)->shouldReceive('refresh')->once()->andReturn('refreshed');
        $this->mock(PreparedResearch::class)->shouldReceive('refresh')->once()->andReturn('unchanged');
        $this->artisan('dashboard:watch', ['--once' => true])->assertSuccessful();
    }

    public function test_backup_expires_and_is_scoped_to_exact_database_and_policy_key(): void
    {
        $backup = new DerivedViewBackup;
        $backup->write('database-a-policy-1', ['revision' => 'valid']);
        $this->assertSame(['revision' => 'valid'], $backup->read('database-a-policy-1'));
        $this->assertNull($backup->read('database-b-policy-1'));
        $this->assertNull($backup->read('database-a-policy-2'));
        $this->travel(8)->days();
        $this->assertNull($backup->read('database-a-policy-1'));
    }

    public function test_http_can_use_backup_after_redis_eviction_without_mongo(): void
    {
        DB::shouldReceive('connection')->never();
        $key = (new ReflectionMethod(PreparedResearch::class, 'key'))->invoke(new PreparedResearch);
        (new DerivedViewBackup)->write($key, ['as_of' => '2026-10-21T00:00:00Z', 'revision' => 'first',
            'seasons_assessed' => 0, 'candidates' => [], 'excluded' => []]);
        $this->getJson('/api/v1/research/scenario?capital=50000&qualification=5&attempts=4&allowance=1&strategy=all&league=all')
            ->assertOk()->assertJsonPath('data_revision', 'first');
    }

    public function test_failed_preparation_is_reported_instead_of_indefinite_warming(): void
    {
        DB::shouldReceive('connection')->never();
        $key = (new ReflectionMethod(PreparedResearch::class, 'key'))->invoke(new PreparedResearch);
        Cache::store('redis')->put($key.':state', ['status' => 'failed'], 100);
        $this->getJson('/api/v1/research/scenario?capital=50000&qualification=5&attempts=4&allowance=1&strategy=all&league=all')
            ->assertStatus(503)->assertJsonPath('preparation.status', 'failed');
    }
}
