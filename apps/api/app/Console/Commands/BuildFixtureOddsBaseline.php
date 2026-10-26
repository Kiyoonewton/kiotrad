<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\FixtureBaselineStore;
use Illuminate\Console\Command;

class BuildFixtureOddsBaseline extends Command
{
    protected $signature = 'provider:build-odds-baseline {competition=all}';

    protected $description = 'Persist exact home/away historical odds minima; update only changed stored seasons';

    public function handle(FixtureBaselineStore $baselines): int
    {
        $competition = $this->argument('competition');
        if ($competition !== 'all' && ! array_key_exists($competition, config('provider.competitions'))) {
            $this->error('Use all or a configured league.');

            return self::FAILURE;
        }
        foreach (array_keys(config('provider.competitions')) as $league) {
            if ($competition !== 'all' && $competition !== $league) {
                continue;
            }
            $summary = $baselines->refresh($league);
            $this->line($league.' | '.json_encode($summary, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
