<?php

namespace App\Console\Commands;

use App\Infrastructure\Notifications\SlackTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationStatus extends Command
{
    protected $signature = 'notifications:status';

    protected $description = 'Read notification health and delivery counts without displaying webhook credentials';

    public function handle(SlackTransport $transport): int
    {
        $this->line('Enabled and valid webhook format: '.($transport->configured() ? 'yes' : 'no'));
        try {
            $db = DB::connection('mongodb')->getDatabase();
            $worker = $db->notification_control->findOne(['_id' => 'worker']);
            $this->line('Last scan UTC: '.($worker['last_scan_at'] ?? 'not observed'));
            $this->line('Source failures on last scan: '.($worker['source_failures'] ?? 'unknown'));
            $this->line('Last delivery status: '.($worker['last_status'] ?? 'not observed'));
            $this->table(['State', 'Messages'], array_map(fn ($status) => [$status,
                $db->notification_outbox->countDocuments(['status' => $status])],
                ['pending', 'sending', 'retry', 'blocked', 'sent', 'expired']));
        } catch (Throwable) {
            $this->error('Notification database unavailable.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
