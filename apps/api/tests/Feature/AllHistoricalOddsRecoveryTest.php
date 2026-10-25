<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AllHistoricalOddsRecoveryTest extends TestCase
{
    public function test_all_leagues_use_only_their_stored_seasons_and_continue_after_a_failure(): void
    {
        $calls = [];
        Artisan::command('provider:recover-odds {competition} {seasons*} {--from-day=1} {--to-day=30}', function () use (&$calls) {
            $calls[] = [$this->argument('competition'), $this->argument('seasons'), $this->option('to-day')];

            return count($calls) === 1 ? 1 : 0;
        });
        $collection = Mockery::mock();
        $collection->shouldReceive('distinct')->once()->with('season_id', ['competition' => 'virtual-football'])->andReturn(['200', '100', '200']);
        $collection->shouldReceive('distinct')->once()->with('season_id', ['competition' => 'virtual-bundesliga'])->andReturn(['300']);
        $collection->shouldReceive('distinct')->once()->with('season_id', ['competition' => 'virtual-euro'])->andReturn(['400']);
        DB::shouldReceive('connection->getDatabase')->times(3)->andReturn((object) ['provider_captures' => $collection]);
        $this->artisan('provider:recover-odds-all', ['--to-day' => 2])->assertFailed();
        $this->assertSame([
            ['virtual-football', ['100'], 2], ['virtual-football', ['200'], 2],
            ['virtual-bundesliga', ['300'], 2], ['virtual-euro', ['400'], 2],
        ], $calls);
    }

    public function test_invalid_scope_does_not_scan_storage(): void
    {
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:recover-odds-all', ['competition' => 'unknown'])->assertFailed();
    }
}
