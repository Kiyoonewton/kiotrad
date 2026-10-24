<?php

namespace App\Console\Commands;

use App\Infrastructure\Notifications\NotificationOutbox;
use App\Infrastructure\Notifications\NotificationWorker;
use Illuminate\Console\Command;
use Throwable;

class WatchNotifications extends Command
{
    protected $signature = 'notifications:watch {--once} {--test : Queue one labeled connectivity test} {--retry-blocked : Retry delivery after fixing Slack configuration}';

    protected $description = 'Deliver deduplicated Slack alerts independently of collection and paper execution';

    public function handle(NotificationWorker $worker, NotificationOutbox $outbox): int
    {
        if ($this->option('test')) {
            $outbox->enqueue('test:'.bin2hex(random_bytes(8)), 'test', ['Purpose' => 'Connectivity test; no strategy qualification or paper stake.']);
        }
        if ($this->option('retry-blocked')) {
            $this->line('Requeued: '.$outbox->retryBlocked());
        }
        do {
            try {
                $status = $worker->tick();
                if (! in_array($status, ['waiting', 'worker_busy'], true)) {
                    $this->line(now('UTC')->toIso8601String().' notifications: '.$status);
                }
                if ($this->option('once')) {
                    return in_array($status, ['blocked', 'disabled_or_unconfigured'], true) ? self::FAILURE : self::SUCCESS;
                }
            } catch (Throwable) {
                $this->error('Notification storage unavailable; retrying without interrupting collection.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(15);
            }
            sleep(2);
        } while (true);
    }
}
