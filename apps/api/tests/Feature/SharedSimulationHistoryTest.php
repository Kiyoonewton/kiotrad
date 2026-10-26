<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\SharedSimulationHistory;
use Tests\TestCase;

class SharedSimulationHistoryTest extends TestCase
{
    public function test_history_reuses_shared_run_and_removes_fixed_target_replay(): void
    {
        $run = ['id' => 'virtual-bundesliga:200:champion-next-win', 'status' => 'win',
            'estimate' => ['plan' => ['profit_per_attempt_minor' => 96100]], 'total_staked_minor' => 274600, 'net_minor' => 96110];
        $this->mock(DashboardSnapshot::class)->shouldReceive('read')->once()->andReturn([
            'shared_simulation' => ['status' => 'available', 'runs' => [$run]],
        ]);
        $page = ['rows' => [['competition' => 'virtual-bundesliga', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
            'simulation' => ['total_staked_minor' => 28600, 'net_minor' => 10010]]], 'pagination' => ['page' => 1]];
        $view = app(SharedSimulationHistory::class)->apply($page);
        $this->assertNull($view['rows'][0]['simulation']);
        $this->assertSame($run, $view['rows'][0]['shared_run']);
        $this->assertSame('shared_capital_simulation', $view['calculation_mode']);
        $this->assertSame(1, $view['pagination']['page']);
    }

    public function test_warming_cache_does_not_fall_back_to_fixed_target_finances(): void
    {
        $this->mock(DashboardSnapshot::class)->shouldReceive('read')->once()->andReturn(['dashboard_cache' => ['status' => 'warming']]);
        $view = app(SharedSimulationHistory::class)->apply(['rows' => [['competition' => 'virtual-football',
            'season_id' => '200', 'strategy_id' => 'champion-next-win', 'simulation' => ['net_minor' => 10000]]]]);
        $this->assertNull($view['rows'][0]['simulation']);
        $this->assertNull($view['rows'][0]['shared_run']);
        $this->assertSame('refreshing', $view['shared_calculation_status']);
    }
}
