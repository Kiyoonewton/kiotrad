<?php

namespace App\Console\Commands;

use App\Infrastructure\Paper\PaperStatus as PortfolioStatus;
use App\Infrastructure\Paper\PaperWorkerHealth;
use App\Infrastructure\Provider\ChampionMonitoring;
use Illuminate\Console\Command;

class PaperStatus extends Command
{
    protected $signature = 'paper:status';

    protected $description = 'Inspect paper funds, worker heartbeat and strategy states without executing trades';

    public function handle(PortfolioStatus $portfolio, PaperWorkerHealth $health, ChampionMonitoring $monitoring): int
    {
        $view = $portfolio->overview();
        $worker = $health->view();
        $money = fn ($value) => $value === null ? 'unknown' : 'NGN '.number_format($value / 100, 2);
        $this->table(['Paper bankroll', 'Value'], [
            ['Status', $view['bankroll']['status']],
            ['Balance', $money($view['bankroll']['balance_minor'])],
            ['Available', $money($view['bankroll']['available_minor'])],
            ['Reserved', $money($view['bankroll']['committed_minor'])],
            ['Next milestone', $money($view['bankroll']['next_milestone_minor'] ?? null)],
            ['Worker', $worker['status']], ['Last heartbeat (UTC)', $worker['observed_at'] ?? 'none'],
            ['Last cycle', $worker['last_result'] ?? 'none'],
        ]);
        $this->table(['League', 'Strategy', 'Observation', 'Reason', 'Team'], array_map(fn ($row) => [
            $row['competition'], $row['strategy_id'] ?? 'all', $row['status'], $row['reason'] ?? '-', $row['team'] ?? 'unknown',
        ], $monitoring->all()));
        $this->line('Read-only status. No paper entries or settlements were executed.');

        return self::SUCCESS;
    }
}
