<?php

namespace Tests\Feature;

use App\Domain\Research\SharedCapitalReplay;
use Tests\TestCase;

class SharedTimingPresentationTest extends TestCase
{
    public function test_archive_blockers_are_explicitly_unverified_and_download_time_is_not_event_finish(): void
    {
        $candidate = ['id' => 'archive', 'fixtures' => []];
        foreach (range(0, 3) as $i) {
            $candidate['fixtures'][] = ['matchday' => $i + 1,
                'scheduled_start_at' => sprintf('2026-10-19T10:%02d:00Z', $i * 4),
                'result_confirmed_at' => '2026-10-24T10:00:00Z', 'won' => true,
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000];
        }
        $later = $candidate;
        $later['id'] = 'later';
        foreach ($later['fixtures'] as &$fixture) {
            $fixture['scheduled_start_at'] = str_replace('10-19', '10-20', $fixture['scheduled_start_at']);
        }
        unset($fixture);
        $view = (new SharedCapitalReplay)->calculate([$candidate, $later], 5000000, '2026-10-25T00:00:00Z');
        $this->assertSame('provisional_capture_timing', $view['timing_quality']);
        $this->assertFalse($view['event_finish_times_verified']);
        $this->assertNull($view['runs'][0]['event_finish_at']);
        $this->assertSame('2026-10-24T10:00:00.000000Z', $view['runs'][0]['result_observed_at']);
        $this->assertSame('unverified_late_archive_timing', $view['runs'][1]['overlap_basis']);
        $this->assertSame('skipped_overlap', $view['runs'][1]['status']);
        $this->assertArrayNotHasKey('net_minor', $view['runs'][1]);
    }
}
