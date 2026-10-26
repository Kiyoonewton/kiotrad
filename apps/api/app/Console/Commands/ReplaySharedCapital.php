<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\SharedCapitalResearch;
use Illuminate\Console\Command;

class ReplaySharedCapital extends Command
{
    protected $signature = 'strategy:replay-shared {--capital=50000}';

    protected $description = 'Replay one shared balance across leagues, selecting only one active qualified run';

    public function handle(SharedCapitalResearch $research): int
    {
        $capital = filter_var($this->option('capital'), FILTER_VALIDATE_INT);
        if ($capital === false || $capital < 1 || $capital > 10000000) {
            $this->error('Use whole-naira starting capital between 1 and 10000000.');

            return self::FAILURE;
        }
        $this->line(json_encode($research->build($capital * 100, now('UTC')->toIso8601String()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
