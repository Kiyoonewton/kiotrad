<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\FixtureBaselineStore;
use App\Infrastructure\Provider\MissingFixtureAudit;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MissingFixtureAuditTest extends TestCase
{
    public function test_prior_captured_price_is_distinguished_from_missing_derived_baseline(): void
    {
        $fixture = ['provider_match_id' => 'target', 'matchday' => 1, 'home_team_uid' => 'a', 'away_team_uid' => 'b',
            'home_team' => 'Home', 'away_team' => 'Away', 'scheduled_start_at' => '2026-10-20T10:00:00Z', 'outcome' => 'H'];
        $prior = [...$fixture, 'provider_match_id' => 'prior', 'scheduled_start_at' => '2026-10-19T10:00:00Z'];
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->andReturn(['matches' => [$fixture]]);
        $collection->shouldReceive('find')->once()->with(Mockery::on(function ($query) {
            return $query['matches']['$elemMatch']['scheduled_start_at']['$lt'] === '2026-10-20T10:00:00Z';
        }), Mockery::any())->andReturn(new ArrayIterator([
            ['_id' => 'capture', 'season_id' => '99', 'matches' => [$prior], 'odds_snapshots' => [
                ['provider_match_id' => 'prior', 'prices' => ['H' => '1.35'], 'captured_at' => '2026-10-24T10:00:00Z'],
            ]],
        ]));
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['provider_captures' => $collection]);
        $baseline = $this->mock(FixtureBaselineStore::class);
        $baseline->shouldReceive('before')->once()->andReturn(null);
        $report = app(MissingFixtureAudit::class)->inspect([['id' => 'run', 'competition' => 'test', 'season_id' => '100',
            'season_start_at' => '2026-10-20T10:00:00Z', 'missing_historical_fixtures' => [$fixture]]]);
        $this->assertSame('baseline_rebuild_needed', $report[0]['reason']);
        $this->assertSame(13500, $report[0]['raw_minimum']['odds_scaled']);
        $this->assertSame(1, $report[0]['matching_prior_captures']);
    }
}
