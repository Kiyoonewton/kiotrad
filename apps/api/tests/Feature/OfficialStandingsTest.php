<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CollectionCycle;
use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FinalStandings;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\SeasonCompleteness;
use App\Infrastructure\Provider\StandingsNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OfficialStandingsTest extends TestCase
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

    public function test_equal_points_use_official_positions_without_alphabetical_fallback(): void
    {
        $result = $this->normalize($this->officialTable('200'));
        $this->assertSame('verified_final', $result['status']);
        $this->assertSame('25', $result['champion']['team_uid']);
        $this->assertSame('Zulu', $result['champion']['team_name']);
        $this->assertSame('provider.pos', $result['ranking_source']);
    }

    public function test_table_season_identity_is_used_instead_of_nested_current_tournament_season(): void
    {
        $table = $this->officialTable('200');
        $this->assertSame('999', $table['doc'][0]['data']['tables'][0]['tournament']['seasonid']);
        $this->assertSame('verified_final', $this->normalize($table)['status']);
        $table['doc'][0]['data']['_id'] = '201';
        $this->expectException(RuntimeException::class);
        $this->normalize($table);
    }

    public function test_totals_must_agree_with_scores_even_when_table_arithmetic_is_valid(): void
    {
        $table = $this->officialTable('200');
        $table['doc'][0]['data']['tables'][0]['tablerows'][0]['goalsForTotal'] = 16;
        $table['doc'][0]['data']['tables'][0]['tablerows'][0]['goalDiffTotal'] = 1;
        $result = $this->normalize($table);
        $this->assertFalse($result['fixture_totals_aligned']);
        $this->assertNull($result['champion']);
    }

    public function test_a_missing_result_cannot_confirm_a_champion_from_a_final_looking_table(): void
    {
        $report = $this->seasonReport();
        $report['matches'][0]['status'] = 'scheduled_or_unconfirmed';
        $result = $this->normalize($this->officialTable('200'), $report['matches']);
        $this->assertSame('blocked', $result['status']);
        $this->assertNull($result['champion']);
    }

    public function test_duplicate_official_positions_are_rejected(): void
    {
        $table = $this->officialTable('200');
        $table['doc'][0]['data']['tables'][0]['tablerows'][1]['pos'] = 1;
        $this->expectException(RuntimeException::class);
        $this->normalize($table);
    }

    public function test_standings_outage_preserves_fixture_results_without_assuming_a_winner(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $report = app(FinalStandings::class)->attach($this->seasonReport());
        $this->assertCount(240, $report['matches']);
        $this->assertTrue($report['completeness']['results_complete']);
        $this->assertSame('unavailable_or_invalid', $report['standings']['status']);
        $this->assertNull($report['standings']['champion']);
    }

    public function test_old_reconciliation_cannot_replace_a_newer_verified_champion(): void
    {
        $report = $this->seasonReport();
        $report['standings'] = $this->normalize($this->officialTable('200'));
        $previous = ['season_id' => '201', 'season_first_kickoff_at' => '2026-10-23T23:00:00+00:00'];
        $this->assertSame($previous, app(FinalStandings::class)->latest($previous, $report, 'capture'));
    }

    public function test_inspection_command_records_season_evidence_and_outputs_verified_champion(): void
    {
        $raw = $this->fullSeason('200', CarbonImmutable::now('UTC')->getTimestamp() - 7000, true);
        Http::fake([
            config('provider.statistics_base').'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $raw]]]),
            config('provider.statistics_base').'stats_season_tables/200' => Http::response($this->officialTable('200')),
        ]);
        $this->mock(CollectionStore::class)->shouldReceive('save')->once()
            ->withArgs(fn ($r) => $r['scope'] === 'reconciliation' && $r['standings']['champion']['team_uid'] === '25')->andReturn('capture');
        $this->artisan('provider:standings', ['competition' => 'virtual-football', 'season' => '200'])
            ->expectsOutputToContain('Champion: Zulu')->assertSuccessful();
    }

    public function test_bootstrap_retries_unverified_table_and_does_not_repeat_completed_history(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $current = $this->fullSeason('201', $now + 60);
        $old = $this->fullSeason('200', $now - 6660, true);
        $metadata = $this->discoveryDocument('201', $now - 60);
        $metadata['doc'][0]['data']['seasons'][] = ['_id' => '200', '_utid' => 31867,
            'current' => false, 'start' => ['uts' => $now - 6780]];
        $base = config('provider.statistics_base');
        Http::fake([
            $base.'uniquetournament_seasons/*' => Http::response($metadata),
            $base.'stats_season_fixtures2/201' => Http::response(['doc' => [['data' => $current]]]),
            $base.'stats_season_fixtures2/200' => Http::response(['doc' => [['data' => $old]]]),
            $base.'stats_season_tables/200' => Http::sequence()->push([], 503)->push($this->officialTable('200')),
            config('provider.odds_base').'vfc_stats_round_odds2/vf:season:201/1' => Http::response($this->roundOdds($current, 1)),
            config('provider.odds_base').'vfc_stats_round_odds2/vf:season:201/2' => Http::response($this->roundOdds($current, 2)),
        ]);
        $this->mock(CollectionStore::class)->shouldReceive('save')->times(5)->andReturn('capture');
        $state = app(CollectionCycle::class)->run('virtual-football', []);
        $this->assertNull($state['latest_champion']);
        $this->assertSame('standings_unverified', $state['pending_seasons']['200']['reason']);
        CarbonImmutable::setTestNow('2026-10-24T00:00:30Z');
        $state = app(CollectionCycle::class)->run('virtual-football', $state);
        $this->assertSame([], $state['pending_seasons']);
        $this->assertSame('200', $state['latest_champion']['season_id']);
        $this->assertSame('2026-10-24T00:00:30+00:00', $state['latest_champion']['confirmed_at']);
        $again = app(CollectionCycle::class)->run('virtual-football', $state);
        $this->assertSame($state['latest_champion'], $again['latest_champion']);
        Http::assertSentCount(12);
    }

    private function normalize(array $payload, ?array $matches = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $feed = new FeedObservation('https://example.test/table', $now, $now, $payload['doc'][0], $payload);

        return (new StandingsNormalizer)->normalize($feed, '200', 31867, $matches ?? $this->seasonReport()['matches']);
    }

    private function seasonReport(): array
    {
        $raw = $this->fullSeason('200', CarbonImmutable::now('UTC')->getTimestamp() - 7000, true);
        $matches = (new FixtureNormalizer)->normalize($raw, '200', 31867);

        return ['competition' => 'virtual-football', 'season_id' => '200', 'matches' => $matches,
            'completeness' => (new SeasonCompleteness)->inspect($matches)];
    }
}
