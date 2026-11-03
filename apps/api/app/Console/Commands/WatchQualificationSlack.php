<?php

namespace App\Console\Commands;

use App\Infrastructure\Notifications\QualificationSlackWorker;
use Illuminate\Console\Command;
use Throwable;

class WatchQualificationSlack extends Command
{
    protected $signature = 'notifications:slack-watch {--once}';

    protected $description = 'Send timely recorded qualification Slack alerts for manual review; no bets or banking actions';

    public function handle(QualificationSlackWorker $worker): int
    {
        do {
            try {
                $status = $worker->tick();
                $this->line(now('UTC')->toIso8601String().' qualification-slack: '.$status);
            } catch (Throwable) {
                $status = 'unavailable';
                $this->error('Qualification Slack check unavailable; credentials and source payloads hidden.');
            }
            if ($this->option('once')) {
                return in_array($status, ['disabled_or_unconfigured', 'unavailable', 'production_only'], true) ? self::FAILURE : self::SUCCESS;
            }
            sleep(5);
        } while (true);
    }
}
