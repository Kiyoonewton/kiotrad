<?php

namespace Tests\Feature;

use App\Domain\Research\FixtureOddsBaseline;
use App\Infrastructure\Provider\FixtureBaselineStore;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class BaselineRefreshEfficiencyTest extends TestCase
{
    public function test_unchanged_seasons_do_not_scan_captures_or_all_historical_minima(): void
    {
        $revision = hash('sha256', 'capture:0:'.FixtureOddsBaseline::VERSION);
        $progress = Mockery::mock();
        $progress->shouldReceive('find')->once()->andReturn(new ArrayIterator([
            ['_id' => 'virtual-euro:10', 'revision' => $revision],
        ]));
        $checkpoints = Mockery::mock();
        $checkpoints->shouldReceive('find')->once()->andReturn(new ArrayIterator([
            ['season_id' => '10', 'capture_id' => 'capture'],
        ]));
        $minima = Mockery::mock();
        $minima->shouldReceive('createIndex')->once();
        $global = Mockery::mock();
        $global->shouldReceive('countDocuments')->once()->andReturn(240);
        DB::shouldReceive('connection->getDatabase')->once()->andReturn((object) [
            'fixture_baseline_progress' => $progress, 'season_checkpoints' => $checkpoints,
            'fixture_odds_season_minima' => $minima, 'fixture_odds_minima' => $global,
        ]);
        $result = (new FixtureBaselineStore)->refresh('virtual-euro');
        $this->assertSame(0, $result['updated_seasons']);
        $this->assertSame(240, $result['priced_pairings']);
    }
}
