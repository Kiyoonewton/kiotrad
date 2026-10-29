<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\PreparedResearch;
use Illuminate\Console\Command;
use Throwable;

class WatchDashboard extends Command
{
    protected $signature = 'dashboard:watch {--once}';

    protected $description = 'Rebuild disposable dashboard snapshots without blocking HTTP or writing provider data';

    public function handle(DashboardSnapshot $snapshot): int
    {
        do {
            $this->line(now('UTC')->toIso8601String().' dashboard: refreshing');
            try {
                $status = $snapshot->refresh();
            } catch (Throwable $exception) {
                report($exception);
                $status = 'refresh_failed';
            }
            $this->line(now('UTC')->toIso8601String().' dashboard: '.$status);
            try {
                $this->line(now('UTC')->toIso8601String().' workbench: checking reusable evidence');
                $prepared = app(PreparedResearch::class)->refresh(function ($phase, $done, $total): void {
                    $this->line(now('UTC')->toIso8601String().' workbench: '.$phase.' '.$done.'/'.$total);
                });
                $this->line(now('UTC')->toIso8601String().' workbench: '.$prepared);
            } catch (Throwable $exception) {
                report($exception);
                $prepared = 'preparation_failed';
                $this->line(now('UTC')->toIso8601String().' workbench: '.$prepared);
            }
            if ($this->option('once')) {
                return $status === 'refreshed' && in_array($prepared, ['prepared', 'unchanged'], true)
                    ? self::SUCCESS : self::FAILURE;
            }
            sleep(30);
        } while (true);
    }
}
