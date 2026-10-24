<?php

namespace App\Console\Commands;

use App\Infrastructure\Paper\PaperRunner;
use App\Infrastructure\Paper\PaperWorkerHealth;
use Illuminate\Console\Command;
use Throwable;

class WatchPaper extends Command
{
    protected $signature = 'paper:watch {--once : Evaluate one paper cycle}';

    protected $description = 'Simulate paper decisions from stored observations; never sends bets to a bookmaker';

    public function handle(PaperRunner $runner, PaperWorkerHealth $health): int
    {
        do {
            try {
                $health->record('checking');
                $status = $runner->tick();
                $health->record('completed', $status);
                if ($status !== 'waiting') {
                    $this->line(now('UTC')->toIso8601String().' paper: '.$status);
                }
            } catch (Throwable $exception) {
                report($exception);
                try {
                    $health->record('retrying', 'cycle_failed');
                } catch (Throwable $healthFailure) {
                    report($healthFailure);
                }
                $this->error('Paper cycle failed; existing funds and decisions remain authoritative.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if ($this->option('once')) {
                return self::SUCCESS;
            }
            sleep(15);
        } while (true);
    }
}
