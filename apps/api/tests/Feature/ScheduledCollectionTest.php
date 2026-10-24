<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\AvailabilityEvidence;
use App\Infrastructure\Provider\CollectionCycle;
use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\SeasonCapture;
use App\Infrastructure\Provider\SeasonCompleteness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ScheduledCollectionTest extends TestCase
{
    use ScheduledFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-24T00:00:00Z');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_two_upcoming_rounds_are_captured_once_and_wake_at_fixture_time(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $season = $this->fullSeason('200', $now + 60);
        $this->fakeFeeds($season, $this->discoveryDocument('200', $now - 60));
        $this->mock(CollectionStore::class)->shouldReceive('save')->twice()->andReturn('capture');
        $cycle = app(CollectionCycle::class);
        $state = $cycle->run('virtual-football', []);
        $this->assertSame(['200:1', '200:2'], $state['captured_round_keys']);
        $this->assertSame('2026-10-24T00:01:01+00:00', $state['next_attempt_at']);
        $again = $cycle->run('virtual-football', $state);
        $this->assertSame(['previously_captured', 'previously_captured'], array_column($again['planned_rounds'], 'status'));
        Http::assertSentCount(6); // Two fixture/discovery reads, but only the first two odds requests.
    }

    public function test_odds_failure_preserves_fixture_results_and_records_unknown_evidence(): void
    {
        $season = $this->fullSeason('200', CarbonImmutable::now('UTC')->getTimestamp() + 60);
        $this->fakeFeeds($season, $this->discoveryDocument('200', 1792799940), true);
        $this->mock(AvailabilityEvidence::class)->shouldReceive('record')->once()->withArgs(fn ($league, $season, $round, $feed) => $league === 'virtual-football' && $season === '200' && $round === 1 && $feed instanceof FeedObservation && $feed->evidence()['http_status'] === 503);
        $report = app(SeasonCapture::class)->run('virtual-football', '200', 'current', []);
        $this->assertCount(240, $report['matches']);
        $this->assertSame('availability_unknown', $report['market_observation']);
        $this->assertSame(['200:2'], $report['captured_round_keys']);
        $this->assertCount(8, $report['odds_snapshots']);
    }

    public function test_next_season_preview_is_separate_from_current_season_round_thirty(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $current = $this->fullSeason('200', $now - 6320, true);
        foreach ($current['matches'] as &$match) {
            if ($match['round'] === 30) {
                $match['periods'] = [];
            }
        }
        unset($match);
        $next = $this->fullSeason('201', $now + 400);
        $this->fakeFeeds($current, $this->discoveryDocument('200', $now - 6440, '201'));
        Http::fake([
            config('provider.statistics_base').'stats_season_fixtures2/201' => Http::response(['doc' => [['data' => $next]]]),
            config('provider.odds_base').'vfc_stats_round_odds2/vf:season:201/1' => Http::response($this->roundOdds($next, 1)),
        ]);
        $scopes = [];
        $this->mock(CollectionStore::class)->shouldReceive('save')->twice()->andReturnUsing(function ($r) use (&$scopes) {
            $scopes[] = [$r['season_id'], $r['scope']];

            return 'capture';
        });
        $state = app(CollectionCycle::class)->run('virtual-football', []);
        $this->assertSame([['200', 'current'], ['201', 'preview']], $scopes);
        $this->assertSame(['200:30', '201:1'], $state['captured_round_keys']);
    }

    public function test_rollover_reconciles_old_results_without_fetching_old_odds(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $current = $this->fullSeason('201', $now + 60);
        $old = $this->fullSeason('200', $now - 7000, true);
        $this->fakeFeeds($current, $this->discoveryDocument('201', $now - 60));
        Http::fake([
            config('provider.statistics_base').'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $old]]]),
            config('provider.statistics_base').'stats_season_tables/200' => Http::response($this->officialTable('200')),
        ]);
        $scopes = [];
        $this->mock(CollectionStore::class)->shouldReceive('save')->twice()->andReturnUsing(function ($r) use (&$scopes) {
            $scopes[] = [$r['season_id'], $r['scope']];

            return 'capture';
        });
        $state = app(CollectionCycle::class)->run('virtual-football', ['current_season_id' => '200', 'last_results_complete' => false]);
        $this->assertSame([['201', 'current'], ['200', 'reconciliation']], $scopes);
        $this->assertSame([], $state['pending_seasons']);
        Http::assertSentCount(6);
    }

    public function test_240_rows_with_a_duplicate_fixture_are_not_a_complete_season(): void
    {
        $season = $this->fullSeason('200', 1792800000, true);
        $normalizer = new FixtureNormalizer;
        $matches = $normalizer->normalize($season, '200', 31867);
        $check = new SeasonCompleteness;
        $this->assertTrue($check->inspect($matches)['results_complete']);
        $matches[239] = $matches[238];
        $this->assertFalse($check->inspect($matches)['results_complete']);
    }

    public function test_incomplete_old_season_is_retained_without_stopping_current_capture(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $current = $this->fullSeason('201', $now + 60);
        $old = $this->fullSeason('200', $now - 7000, true);
        array_pop($old['matches']);
        $this->fakeFeeds($current, $this->discoveryDocument('201', $now - 60));
        Http::fake([config('provider.statistics_base').'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $old]]])]);
        $this->mock(CollectionStore::class)->shouldReceive('save')->twice()->andReturn('capture');
        $state = app(CollectionCycle::class)->run('virtual-football', ['current_season_id' => '200', 'last_results_complete' => false]);
        $this->assertSame('201', $state['current_season_id']);
        $this->assertFalse($state['pending_seasons']['200']['completeness']['results_complete']);
        $this->assertSame('2026-10-24T00:01:30+00:00', $state['pending_seasons']['200']['next_attempt_at']);
        $this->assertSame('2026-10-24T00:01:01+00:00', $state['next_attempt_at']);
    }

    public function test_stale_discovery_cannot_move_current_season_backwards(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $this->fakeFeeds($this->fullSeason('200', $now + 60), $this->discoveryDocument('200', $now - 60));
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $this->expectException(RuntimeException::class);
        app(CollectionCycle::class)->run('virtual-football', [
            'current_season_id' => '201', 'current_season_start_uts' => $now + 600,
        ]);
    }

    private function fakeFeeds(array $season, array $discovery, bool $failFirst = false): void
    {
        $id = $season['_id'];
        $routes = [
            config('provider.statistics_base').'uniquetournament_seasons/*' => Http::response($discovery),
            config('provider.statistics_base').'stats_season_fixtures2/'.$id => Http::response(['doc' => [['data' => $season]]]),
        ];
        foreach ([1, 2, 30] as $round) {
            $routes[config('provider.odds_base')."vfc_stats_round_odds2/vf:season:{$id}/{$round}"] = $failFirst && $round === 1
                ? Http::response([], 503) : Http::response($this->roundOdds($season, $round));
        }
        Http::fake($routes);
    }
}
