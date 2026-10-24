<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CollectionCycle;
use App\Infrastructure\Provider\CollectionGap;
use App\Infrastructure\Provider\CollectorState;
use App\Infrastructure\Provider\CollectorTick;
use App\Infrastructure\Provider\GapSeasonPlan;
use App\Infrastructure\Provider\ResultRepairRequests;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class GapRecoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_planned_wait_is_not_a_gap_but_an_overdue_restart_is(): void
    {
        $gaps = new CollectionGap;
        $previous = ['next_attempt_at' => '2026-10-24T10:04:00+00:00', 'collection_observed_at' => '2026-10-24T10:00:00+00:00'];
        $this->assertNull($gaps->detect('virtual-football', $previous, '2026-10-24T10:03:00+00:00', false));
        $gap = $gaps->detect('virtual-football', $previous, '2026-10-24T11:00:00+00:00', false);
        $this->assertSame('overdue_collection', $gap['reason']);
        $this->assertSame('2026-10-24T10:04:00+00:00', $gap['expected_at']);
        $this->assertSame($gap, $gaps->detect('virtual-football', [...$previous, 'collection_gap' => $gap], '2026-10-24T11:02:00+00:00', true));
    }

    public function test_only_advertised_intermediate_seasons_are_queued_not_guessed_numeric_ids(): void
    {
        $previous = ['current_season_id' => '100', 'current_season_start_uts' => 1000];
        $seasons = [['_id' => '777', 'start' => ['uts' => 3000]], ['_id' => '104', 'start' => ['uts' => 2000]],
            ['_id' => '100', 'start' => ['uts' => 1000]], ['_id' => '900', 'start' => ['uts' => 4000]]];
        $this->assertSame(['104', '777'], (new GapSeasonPlan)->intermediate($previous, $seasons, '900', 4000));
    }

    public function test_failure_and_resumption_keep_the_same_gap_without_claiming_repairs_are_finished(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:00:00+00:00');
        $state = new class implements CollectorState
        {
            public array $value = [];

            public function read(string $competition): array
            {
                return $this->value;
            }

            public function write(string $competition, array $state): void
            {
                $this->value = [...$this->value, ...$state];
            }
        };
        $this->app->instance(CollectorState::class, $state);
        $this->mock(ResultRepairRequests::class)->shouldReceive('pending')->twice()->andReturn([]);
        $cycle = $this->mock(CollectionCycle::class);
        $cycle->shouldReceive('run')->once()->andThrow(new RuntimeException('unavailable'));
        $cycle->shouldReceive('run')->once()->andReturn(['current_season_id' => '201',
            'pending_seasons' => ['200' => ['failures' => 1]], 'next_attempt_at' => '2026-10-24T10:02:00+00:00']);
        $tick = app(CollectorTick::class);
        $this->assertSame('retrying', $tick->run('virtual-football'));
        $id = $state->value['collection_gap']['id'];
        CarbonImmutable::setTestNow('2026-10-24T10:00:45+00:00');
        $this->assertSame('collected', $tick->run('virtual-football'));
        $this->assertNull($state->value['collection_gap']);
        $this->assertSame($id, $state->value['last_collection_gap']['id']);
        $this->assertSame(['200'], $state->value['last_collection_gap']['pending_season_ids']);
        $this->assertSame('pending', $state->value['last_collection_gap']['reconciliation_status']);
        $this->assertFalse($state->value['last_collection_gap']['opportunities_replayed']);
    }

    public function test_missing_exact_paid_match_stays_queued_after_general_season_repair(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:00:00+00:00');
        $state = $this->mock(CollectorState::class);
        $state->shouldReceive('read')->once()->andReturn([]);
        $state->shouldReceive('write')->once()->with('virtual-football', \Mockery::on(fn ($row) => $row['pending_seasons']['150']['reason'] === 'paid_match_unconfirmed'
            && $row['next_attempt_at'] === '2026-10-24T10:05:00+00:00'
        ));
        $repairs = $this->mock(ResultRepairRequests::class);
        $repairs->shouldReceive('pending')->once()->andReturn(['150']);
        $repairs->shouldReceive('complete')->once()->with('virtual-football', '150')->andReturn(false);
        $this->mock(CollectionCycle::class)->shouldReceive('run')->once()->andReturn(['current_season_id' => '201',
            'pending_seasons' => [], 'next_attempt_at' => '2026-10-24T10:10:00+00:00']);
        $this->assertSame('collected', app(CollectorTick::class)->run('virtual-football'));
    }

    public function test_pending_paper_season_is_prioritized_and_acknowledged_after_repair(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:00:00+00:00');
        $state = $this->mock(CollectorState::class);
        $state->shouldReceive('read')->once()->andReturn(['pending_seasons' => ['199' => ['next_attempt_at' => '2026-10-24T09:59:00+00:00', 'failures' => 0]]]);
        $state->shouldReceive('write')->once();
        $repairs = $this->mock(ResultRepairRequests::class);
        $repairs->shouldReceive('pending')->once()->with('virtual-football')->andReturn(['150']);
        $repairs->shouldReceive('complete')->once()->with('virtual-football', '150')->andReturn(true);
        $this->mock(CollectionCycle::class)->shouldReceive('run')->once()->with('virtual-football', \Mockery::on(
            fn ($previous) => array_map('strval', array_keys($previous['pending_seasons'])) === ['150', '199']
        ))->andReturn(['current_season_id' => '201', 'pending_seasons' => ['199' => []], 'next_attempt_at' => '2026-10-24T10:01:00+00:00']);
        $this->assertSame('collected', app(CollectorTick::class)->run('virtual-football'));
    }
}
