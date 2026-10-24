<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\DashboardSnapshot;
use Illuminate\Console\Command;
use Throwable;

class WatchDashboard extends Command
{
    protected $signature = 'dashboard:watch {--once}';

    protected $description = 'Rebuild disposable dashboard snapshots without blocking HTTP or writing provider data';

    public function handle(DashboardSnapshot $snapshot): int
    {
        do {
            try {
                $status = $snapshot->refresh();
            } catch (Throwable) {
                $status = 'refresh_failed';
            }
            $this->line(now('UTC')->toIso8601String().' dashboard: '.$status);
            if ($this->option('once')) {
                return $status === 'refreshed' ? self::SUCCESS : self::FAILURE;
            }
            sleep(30);
        } while (true);
    }
}
