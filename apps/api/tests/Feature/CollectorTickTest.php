<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CollectionCycle;
use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\CollectorState;
use App\Infrastructure\Provider\CollectorTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectorTickTest extends TestCase
{
    use ProviderFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_success_saves_normalized_data_and_clears_prior_failures(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T00:00:00Z');
        $this->mock(CollectionCycle::class)->shouldReceive('run')->once()
            ->with('virtual-football', ['failure_count' => 2])
            ->andReturn(['next_attempt_at' => '2026-10-24T00:01:01+00:00']);
        $state = $this->mock(CollectorState::class);
        $state->shouldReceive('read')->once()->with('virtual-football')->andReturn(['failure_count' => 2]);
        $state->shouldReceive('write')->once()->with('virtual-football', [
            'failure_count' => 0, 'last_failure_at' => null, 'next_attempt_at' => '2026-10-24T00:01:01+00:00',
        ]);
        $this->assertSame('collected', app(CollectorTick::class)->run('virtual-football'));
    }

    public function test_failures_back_off_across_worker_restarts_without_saving_capture(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $state = $this->mock(CollectorState::class);
        $waiting = ['failure_count' => 1, 'next_attempt_at' => '2026-10-24T00:00:45+00:00'];
        $state->shouldReceive('read')->times(3)->with('virtual-football')->andReturn([], $waiting, $waiting);
        $state->shouldReceive('write')->once()->with('virtual-football', [
            'failure_count' => 1, 'last_failure_at' => '2026-10-24T00:00:00+00:00',
            'next_attempt_at' => '2026-10-24T00:00:45+00:00',
        ]);
        $state->shouldReceive('write')->once()->with('virtual-football', [
            'failure_count' => 2, 'last_failure_at' => '2026-10-24T00:00:45+00:00',
            'next_attempt_at' => '2026-10-24T00:02:15+00:00',
        ]);
        $this->app->instance(CollectorState::class, $state);
        $this->assertSame('retrying', app(CollectorTick::class)->run('virtual-football'));
        $this->assertSame('waiting', app(CollectorTick::class)->run('virtual-football'));
        Http::assertSentCount(1);
        CarbonImmutable::setTestNow('2026-10-24T00:00:45Z');
        $this->assertSame('retrying', app(CollectorTick::class)->run('virtual-football'));
    }
}
