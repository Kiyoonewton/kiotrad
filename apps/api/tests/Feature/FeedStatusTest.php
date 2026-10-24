<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\FeedStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FeedStatusTest extends TestCase
{
    public function test_database_outage_returns_unavailable_without_connection_details(): void
    {
        DB::shouldReceive('connection->getDatabase')->once()->andThrow(new RuntimeException('private URI'));
        $rows = (new FeedStatus)->all();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('unavailable', $row['feed_status']);
            $this->assertNull($row['last_success_at']);
        }
        $this->assertStringNotContainsString('private URI', json_encode($rows));
    }

    public function test_capture_age_and_failures_are_not_mistaken_for_live_matches(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T00:03:00Z');
        try {
            $feeds = new FeedStatus;
            $league = ['id' => 'virtual-football'];
            $capture = ['observed_at' => '2026-10-24T00:02:00+00:00', 'season_id' => '200'];
            $this->assertSame('fresh', $feeds->summarize($league, $capture, [])['feed_status']);
            $capture['observed_at'] = '2026-10-24T00:00:00+00:00';
            $this->assertSame('stale', $feeds->summarize($league, $capture, [])['feed_status']);
            $this->assertSame('scheduled', $feeds->summarize($league, $capture, ['next_attempt_at' => '2026-10-24T00:04:00+00:00'])['feed_status']);
            $this->assertSame('retrying', $feeds->summarize($league, $capture, ['failure_count' => 1])['feed_status']);
            $this->assertSame('not_connected', $feeds->summarize($league, null, [])['feed_status']);
            $this->assertFalse($feeds->summarize($league, $capture, [])['data_complete']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
