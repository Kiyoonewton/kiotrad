<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\CollectorTick;
use Illuminate\Console\Command;
use Throwable;

class WatchProvider extends Command
{
    protected $signature = 'provider:watch {competition} {--once : Run one due poll and exit}';

    protected $description = 'Poll one league with persisted backoff; no strategy or betting execution';

    public function handle(CollectorTick $tick): int
    {
        $league = $this->argument('competition');
        if (! array_key_exists($league, config('provider.competitions'))) {
            $this->error('Use a configured competition ID.');

            return self::FAILURE;
        }
        // Prevent duplicate workers in the same container. Deploy one replica per league.
        $lock = fopen(sys_get_temp_dir().'/kiotrad-'.$league.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A worker for this league is already running in this container.');

            return self::FAILURE;
        }
        try {
            do {
                try {
                    $status = $tick->run($league);
                    if ($status !== 'waiting') {
                        $this->line(now('UTC')->toIso8601String()." {$league}: {$status}");
                    }
                } catch (Throwable $exception) {
                    // Storage failures cannot persist backoff. Still avoid a hot retry loop.
                    report($exception);
                    $this->error("{$league}: storage unavailable; retrying after 45 seconds.");
                    if ($this->option('once')) {
                        return self::FAILURE;
                    }
                    sleep(45);
                    continue;
                }
                if ($this->option('once')) {
                    return $status === 'retrying' ? self::FAILURE : self::SUCCESS;
                }
                sleep(1);
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
