<?php

namespace Tests\Feature;

use App\Domain\Research\SharedTimingAudit;
use Tests\TestCase;

class SharedTimingAuditTest extends TestCase
{
    public function test_archive_delay_and_affected_runs_are_reported_without_inventing_event_end(): void
    {
        $runs = [['id' => 'archive', 'status' => 'win', 'entry_at' => '2026-10-19T03:27:51Z',
            'released_at' => '2026-10-24T14:55:09Z', 'fixtures' => [
                ['matchday' => 1, 'scheduled_start_at' => '2026-10-19T03:27:51Z', 'result_confirmed_at' => '2026-10-24T14:55:09Z'],
                ['matchday' => 2, 'scheduled_start_at' => '2026-10-19T03:31:31Z'],
            ]], ['id' => 'later', 'status' => 'skipped_overlap', 'blocked_by' => 'archive',
                'entry_at' => '2026-10-20T03:27:51Z', 'fixtures' => []]];
        $audit = (new SharedTimingAudit)->inspect($runs);
        $this->assertFalse($audit['actual_overlap_verified']);
        $this->assertSame(['later'], $audit['runs'][0]['blocks_runs']);
        $this->assertCount(1, $audit['runs'][0]['confirmation_crosses_scheduled_attempts']);
        $this->assertEqualsWithDelta(131.455, $audit['runs'][0]['capture_span_hours'], 0.001);
        $this->assertNull($audit['runs'][1]['recorded_confirmation_at']);
        $this->assertArrayNotHasKey('balance_minor', $audit);
    }

    public function test_offsets_are_compared_as_instants(): void
    {
        $audit = (new SharedTimingAudit)->inspect([['id' => 'live', 'status' => 'win',
            'entry_at' => '2026-10-20T10:00:00Z', 'released_at' => '2026-10-20T11:02:00+01:00',
            'fixtures' => [['scheduled_start_at' => '2026-10-20T10:00:00Z', 'result_confirmed_at' => '2026-10-20T11:02:00+01:00'],
                ['scheduled_start_at' => '2026-10-20T10:04:00Z']]]]);
        $this->assertSame([], $audit['runs'][0]['confirmation_crosses_scheduled_attempts']);
        $this->assertEqualsWithDelta(0.033, $audit['runs'][0]['capture_span_hours'], 0.001);
    }
}
