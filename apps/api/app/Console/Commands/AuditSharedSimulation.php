<?php

namespace App\Console\Commands;

use App\Domain\Research\SharedTimingAudit;
use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\MissingFixtureAudit;
use Illuminate\Console\Command;

class AuditSharedSimulation extends Command
{
    protected $signature = 'strategy:audit-shared {--fixtures : Also scan stored captures for missing fixture baselines}';

    protected $description = 'Read-only audit of cached shared simulation timing, blockers and missing prior odds';

    public function handle(DashboardSnapshot $dashboard, MissingFixtureAudit $fixtures): int
    {
        $view = $dashboard->read();
        $shared = $view['shared_simulation'] ?? [];
        if (($shared['status'] ?? null) !== 'available') {
            $this->error('Shared dashboard snapshot is not ready. Wait for dashboard: refreshed.');

            return self::FAILURE;
        }
        $runs = $shared['runs'] ?? [];
        $report = ['cache' => $view['dashboard_cache'] ?? null,
            'policy_version' => $shared['policy_version'] ?? null,
            'timing' => (new SharedTimingAudit)->inspect($runs),
            'missing_fixtures' => $this->option('fixtures') ? $fixtures->inspect($runs) : 'Use --fixtures to scan stored evidence.',
            'notice' => 'Read-only audit. No corrected finish times, overlap decisions or balances are inferred.'];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
