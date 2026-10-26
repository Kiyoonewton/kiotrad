<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\FixtureBaselineStore;
use App\Infrastructure\Provider\QualifiedRunSizer;
use Illuminate\Console\Command;

class SizeQualifiedRun extends Command
{
    protected $signature = 'strategy:size-qualified {competition} {season} {--capital=50000}';

    protected $description = 'Preview qualified-run profit targets using prior exact-fixture minima and a double-cost allowance';

    public function handle(FixtureBaselineStore $baselines, QualifiedRunSizer $sizer): int
    {
        $competition = $this->argument('competition');
        $season = $this->argument('season');
        $capital = filter_var($this->option('capital'), FILTER_VALIDATE_INT);
        if (! array_key_exists($competition, config('provider.competitions')) || ! ctype_digit($season)
            || strlen($season) > 20 || $capital === false || $capital < 1 || $capital > 10000000) {
            $this->error('Use a configured league, numeric season and whole-naira capital between 1 and 10000000.');

            return self::FAILURE;
        }
        $baselines->refresh($competition);
        $this->line(json_encode($sizer->season($competition, $season, $capital * 100), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
