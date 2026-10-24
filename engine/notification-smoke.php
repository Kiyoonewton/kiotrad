<?php

use App\Infrastructure\Notifications\NotificationOutbox;
use App\Infrastructure\Notifications\NotificationWorker;
use App\Infrastructure\Notifications\OperationalNotifications;
use App\Infrastructure\Notifications\SlackTransport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Notification smoke check requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_notification_smoke']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
$transport = new class extends SlackTransport
{
    public int $calls = 0;

    public array $result = ['status' => 'sent'];

    public function configured(): bool
    {
        return true;
    }

    public function send(array $payload): array
    {
        $this->calls++;

        return $this->result;
    }
};
try {
    $db->drop();
    CarbonImmutable::setTestNow('2026-10-24T10:00:00Z');
    $outbox = new NotificationOutbox;
    $started = $outbox->startedAt();
    $outbox->enqueue('synthetic-event', 'test', ['Scope' => 'Synthetic CI only']);
    $outbox->enqueue('synthetic-event', 'test', ['Scope' => 'Retry must not replace original']);
    $assert($db->notification_outbox->countDocuments() === 1, 'Repeated discovery duplicated alert.');
    $assert($outbox->deliver($transport) === 'sent', 'Expected successful delivery.');
    $assert($outbox->deliver($transport) === 'waiting', 'Already sent alert was redelivered.');
    $assert($transport->calls === 1, 'Duplicate HTTP call.');
    $assert((new NotificationOutbox)->startedAt() === $started, 'Restart changed historical alert boundary.');

    $outbox->enqueue('expired-opportunity', 'qualified', ['Scope' => 'Expired'], '2026-10-24T09:59:00+00:00');
    $assert($outbox->deliver($transport) === 'expired', 'Closed opportunity was sent.');
    $assert($transport->calls === 1, 'Expired opportunity made HTTP request.');

    $transport->result = ['status' => 'retry', 'reason' => 'rate_limited', 'delay' => 120];
    $outbox->enqueue('retry-event', 'pending', ['Scope' => 'Synthetic retry']);
    $assert($outbox->deliver($transport) === 'retry', 'Rate limit not retained.');
    $outbox->enqueue('other-event', 'test', ['Scope' => 'Must respect shared Slack pause']);
    $assert($outbox->deliver($transport) === 'waiting', 'Rate limit did not pause other messages.');
    CarbonImmutable::setTestNow('2026-10-24T10:02:01Z');
    $transport->result = ['status' => 'sent'];
    $assert((new NotificationOutbox)->deliver($transport) === 'sent', 'Pending alert lost after restart.');
    $assert($outbox->deliver($transport) === 'sent', 'Second pending alert lost.');

    $transport->result = ['status' => 'blocked', 'reason' => 'http_403', 'delay' => 60];
    $outbox->enqueue('blocked-event', 'test', ['Scope' => 'Configuration repair']);
    $assert($outbox->deliver($transport) === 'blocked', 'Invalid webhook did not block retry.');
    $assert($outbox->deliver($transport) === 'waiting', 'Blocked alert automatically retried.');
    $assert($outbox->retryBlocked() === 1, 'Blocked retry command lost event.');
    $transport->result = ['status' => 'sent'];
    $assert($outbox->deliver($transport) === 'sent', 'Repaired configuration did not resume.');

    $outbox->enqueue('crash-event', 'test', ['Scope' => 'Lease recovery']);
    $db->notification_outbox->updateOne(['_id' => hash('sha256', 'crash-event')], ['$set' => [
        'status' => 'sending', 'lease' => 'crashed-worker', 'next_attempt_at' => '2026-10-24T10:03:00+00:00',
    ]]);
    $assert($outbox->deliver($transport) === 'waiting', 'Active delivery lease stolen.');
    CarbonImmutable::setTestNow('2026-10-24T10:03:01Z');
    $assert($outbox->deliver($transport) === 'sent', 'Expired delivery lease did not recover.');
    $db->collector_states->insertOne(['_id' => 'virtual-football', 'next_attempt_at' => '2026-10-24T09:59:00+00:00',
        'collection_observed_at' => '2026-10-24T09:58:00+00:00', 'pending_seasons' => [
            '100' => ['failures' => 3, 'reason' => 'results_or_fixtures_missing', 'next_attempt_at' => '2026-10-24T10:10:00+00:00'],
        ], 'planned_rounds' => [['season_id' => '200', 'matchday' => 5, 'status' => 'availability_unknown']]]);
    $source = new OperationalNotifications;
    $source->scan($outbox, $started);
    $count = $db->notification_outbox->countDocuments();
    $source->scan($outbox, $started);
    $assert($db->notification_outbox->countDocuments() === $count, 'Operational scans duplicated alerts.');
    $assert($db->notification_outbox->countDocuments(['type' => 'unknown_market']) === 1, 'Unknown market not reported.');
    $assert($db->notification_outbox->countDocuments(['type' => 'blocked_market']) === 0, 'Missing odds falsely confirmed a market block.');
    $db->market_blockages->insertOne(['_id' => 'synthetic-proof', 'classification' => 'operator_confirmed',
        'competition' => 'virtual-football', 'season_id' => '200', 'matchday' => 5, 'reason' => 'Synthetic manual evidence',
        'evidence_sha256' => 'synthetic', 'observed_at' => '2026-10-24T10:03:00+00:00']);
    $source->scan($outbox, $started);
    $assert($db->notification_outbox->countDocuments(['type' => 'blocked_market']) === 1, 'Explicit block record not reported.');
    $app->instance(SlackTransport::class, $transport);
    $worker = $app->make(NotificationWorker::class);
    $worker->tick();
    $health = $db->notification_control->findOne(['_id' => 'worker']);
    $assert($health['source_failures'] === 0, 'A real notification source could not read its MongoDB data.');
    $before = $transport->calls;
    $worker->tick();
    $assert($transport->calls === $before, 'Worker exceeded shared two-second delivery cadence.');
    echo "Notification smoke passed: deduplication, persistent retry, expiry, shared rate limit, blocked repair and lease recovery.\n";
} finally {
    CarbonImmutable::setTestNow();
    $db->drop();
}
