<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ProviderProbe;
use App\Infrastructure\Provider\SeasonDiscovery;
use App\Infrastructure\Provider\SportradarClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProviderProbeTest extends TestCase
{
    use ProviderFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_probe_discovers_current_season_without_archive_browser_and_joins_odds(): void
    {
        CarbonImmutable::setTestNow('2026-10-23T23:00:00Z');
        config(['provider.competitions.virtual-football.seed' => '100']);
        $base = config('provider.statistics_base');
        $odds = config('provider.odds_base');
        Http::preventStrayRequests();
        Http::fake([
            $base.'uniquetournament_seasons/s-100' => Http::response(['doc' => [[
                'event' => 'uniquetournament_seasons', '_dob' => 1792796400, '_maxage' => 40,
                'data' => ['seasons' => [['_id' => '200', '_utid' => 31867, 'current' => true]]],
            ]]]),
            $base.'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $this->seasonFixture()]]]),
            $odds.'vfc_stats_round_odds2/vf:season:200/1' => Http::response(['doc' => [$this->oddsFixture()]]),
        ]);
        $result = app(ProviderProbe::class)->run('virtual-football');
        $this->assertSame('200', $result['season_id']);
        $this->assertSame(1, $result['verified_pre_match_prices']);
        $this->assertCount(3, $result['observations']);
        $this->assertSame('2026-10-23T23:00:52+00:00', $result['next_scheduled_start_at']);
        Http::assertSentCount(3);
    }

    public function test_provider_exception_is_not_mistaken_for_empty_valid_data(): void
    {
        Http::fake(['*' => Http::response(['doc' => [['event' => 'exception', 'data' => ['message' => 'Unavailable']]]])]);
        $this->expectException(RuntimeException::class);
        app(SportradarClient::class)->statistics('stats_season_meta/100');
    }

    public function test_invalid_diagnostic_arguments_make_no_network_requests(): void
    {
        Http::fake();
        $this->artisan('provider:probe', ['competition' => 'unknown'])->assertFailed();
        $this->artisan('provider:probe', ['--round' => '31'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_discovery_does_not_choose_between_multiple_current_seasons(): void
    {
        Http::fake(['*' => Http::response(['doc' => [['data' => ['seasons' => [
            ['_id' => '200', '_utid' => 31867, 'current' => true],
            ['_id' => '201', '_utid' => 31867, 'current' => true],
        ]]]]])]);
        $this->expectException(RuntimeException::class);
        app(SeasonDiscovery::class)->discover(['seed' => '100', 'tournament_uid' => 31867]);
    }
}
