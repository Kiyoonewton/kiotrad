<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CollectionStore;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HistoricalOddsRecoveryTest extends TestCase
{
    use ScheduledFixtures;

    private function feeds(bool $valid = true): void
    {
        config(['provider.archive_raw_responses' => false]);
        $season = $this->fullSeason('200', 1700000000, true);
        Http::preventStrayRequests();
        Http::fake([
            config('provider.statistics_base').'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $season]]]),
            config('provider.odds_base').'vfc_stats_round_odds2/vf:season:200/1' => Http::response($valid ? $this->roundOdds($season, 1) : ['doc' => [['data' => ['odds' => []]]]]),
        ]);
    }

    private function stored(array $prices = []): void
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('find')->once()->andReturn(new ArrayIterator([['odds_snapshots' => $prices]]));
        DB::shouldReceive('connection->getDatabase')->once()->andReturn((object) ['provider_captures' => $collection]);
    }

    public function test_recovers_archived_prices_with_actual_retrieval_time_and_no_pre_match_claim(): void
    {
        $this->feeds();
        $this->stored();
        $this->mock(CollectionStore::class)->shouldReceive('save')->once()->with(Mockery::on(fn ($r) =>
            count($r['odds_snapshots']) === 8 && $r['scope'] === 'historical_odds'
            && $r['odds_snapshots'][0]['pre_match_verified'] === false
            && $r['observed_at'] === $r['odds_snapshots'][0]['captured_at']
            && $r['observed_at'] > $r['matches'][0]['scheduled_start_at']
        ))->andReturn('saved');
        $this->artisan('provider:recover-odds', ['competition' => 'virtual-football', 'seasons' => ['200'], '--to-day' => 1])
            ->expectsOutputToContain('eight archived prices saved')->assertSuccessful();
    }

    public function test_rerun_skips_a_round_with_all_eight_prices(): void
    {
        $this->feeds();
        $this->stored(array_map(fn ($i) => ['provider_match_id' => 'vf:match:'.(200000 + $i),
            'prices' => ['H' => '2.5', 'D' => '3', 'A' => '2.8']], range(0, 7)));
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $this->artisan('provider:recover-odds', ['competition' => 'virtual-football', 'seasons' => ['200'], '--to-day' => 1])
            ->expectsOutputToContain('already priced; skipped')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_missing_prices_remain_retryable_without_saving_a_false_success(): void
    {
        $this->feeds(false);
        $this->stored();
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $this->artisan('provider:recover-odds', ['competition' => 'virtual-football', 'seasons' => ['200'], '--to-day' => 1])
            ->expectsOutputToContain('recovery failed; rerun to retry')->assertFailed();
    }
}
