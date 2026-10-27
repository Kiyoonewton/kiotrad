<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\FixtureBaselineStore;
use App\Infrastructure\Provider\QualifiedRunSizer;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class QualifiedRunSizingTest extends TestCase
{
    public function test_historical_lookup_is_exact_venue_and_excludes_the_target_and_later_seasons(): void
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('find')->once()->with(Mockery::on(fn ($filter) => $filter['competition'] === 'virtual-football' && $filter['home_team_uid'] === '1'
            && $filter['away_team_uid'] === '2' && $filter['season_start_at']['$lt'] === '2026-10-01T00:00:00Z'
            && $filter['minima.A.odds_scaled']['$gt'] === 10000
        ), Mockery::any())->andReturn(new ArrayIterator([]));
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['fixture_odds_season_minima' => $collection]);
        $this->assertNull((new FixtureBaselineStore)->before('virtual-football',
            ['home_team_uid' => '1', 'away_team_uid' => '2'], 'A', '2026-10-01T00:00:00Z'));
    }

    public function test_only_qualified_runs_are_sized_and_upcoming_market_odds_are_not_required(): void
    {
        $matches = array_map(fn ($day) => ['matchday' => $day, 'home_team_uid' => '1', 'away_team_uid' => '2',
            'home_team' => 'Synthetic', 'away_team' => 'Opponent', 'scheduled_start_at' => '2026-10-01T00:00:00Z'], range(6, 9));
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->andReturn(['matches' => $matches]);
        DB::shouldReceive('connection->getDatabase')->andReturn((object) ['provider_captures' => $collection]);
        $this->mock(ChampionMonitoring::class)->shouldReceive('forSeason')->with('virtual-football', '100')->andReturn([
            ['strategy_id' => 'champion-next-win', 'trigger_day' => 5, 'team_uid' => '1', 'team' => 'Synthetic', 'evidence_ready_at' => '2026-10-01T00:00:00Z'],
            ['strategy_id' => 'champion-next-season', 'trigger_day' => 1, 'team_uid' => '1'],
        ]);
        $this->mock(FixtureBaselineStore::class)->shouldReceive('before')->times(4)->andReturn(['odds_scaled' => 20000, 'season_id' => '99']);
        $view = app(QualifiedRunSizer::class)->season('virtual-football', '100', 5000000);
        $this->assertCount(1, $view['runs']);
        $this->assertCount(4, $view['runs'][0]['fixtures']);
        $this->assertSame('estimated', $view['runs'][0]['estimate']['status']);
        $this->assertFalse($view['runs'][0]['actual_staking_changed']);
    }
}
