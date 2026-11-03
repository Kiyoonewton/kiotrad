<?php

namespace App\Console\Commands;

use App\Infrastructure\Notifications\QualificationEmailWorker;
use Illuminate\Console\Command;
use Throwable;

class WatchQualificationEmail extends Command
{
    protected $signature = 'notifications:email-watch {--once}';

    protected $description = 'Send timely recorded qualification emails for manual review; no bets or banking actions';

    public function handle(QualificationEmailWorker $worker): int
    {
        do {
            try {
                $status = $worker->tick();
                $this->line(now('UTC')->toIso8601String().' qualification-email: '.$status);
            } catch (Throwable) {
                $status = 'unavailable';
                $this->error('Qualification email check unavailable; credentials and source payloads hidden.');
            }
            if ($this->option('once')) {
                return in_array($status, ['disabled_or_unconfigured', 'unavailable', 'production_only'], true) ? self::FAILURE : self::SUCCESS;
            }
            sleep(5);
        } while (true);
    }
}
