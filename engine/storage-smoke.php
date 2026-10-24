<?php

// CI-only integration check against an isolated, disposable database.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Storage smoke check requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_storage_smoke']);
Illuminate\Support\Facades\DB::purge('mongodb');
$database = Illuminate\Support\Facades\DB::connection('mongodb')->getDatabase();
$store = new App\Infrastructure\Provider\MongoCollectionStore;
$report = [
    'competition' => 'virtual-football', 'season_id' => '200',
    'observed_at' => '2026-10-24T00:00:00+00:00',
    'matches' => [['provider_match_id' => 'synthetic:1', 'result' => null]],
    'odds_snapshots' => [],
];
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    $database->drop();
    $first = $store->save($report);
    $assert($store->save($report) === $first, 'Exact retry changed capture identity.');
    $assert($database->provider_captures->countDocuments() === 1, 'Retry duplicated capture.');
    $newer = $report;
    $newer['observed_at'] = '2026-10-24T00:01:00+00:00';
    $newer['matches'][0]['result'] = 'H';
    $second = $store->save($newer);
    $store->save($report);
    $checkpoint = $database->provider_checkpoints->findOne(['_id' => 'virtual-football']);
    $assert($checkpoint['capture_id'] === $second, 'Older retry regressed checkpoint.');
    $old = $database->provider_captures->findOne(['_id' => $first]);
    $assert($old['matches'][0]['result'] === null, 'Later result rewrote original evidence.');
    $euro = $report;
    $euro['competition'] = 'virtual-euro';
    $store->save($euro);
    $assert($database->provider_checkpoints->countDocuments() === 2, 'League checkpoints collided.');
    $assert($database->provider_captures->countDocuments() === 3, 'Unexpected capture count.');
    $state = new App\Infrastructure\Provider\MongoCollectorState;
    $assert($state->read('virtual-football') === [], 'Unexpected initial worker state.');
    $state->write('virtual-football', ['failure_count' => 2, 'next_attempt_at' => '2026-10-24T00:02:15+00:00']);
    $restarted = new App\Infrastructure\Provider\MongoCollectorState;
    $assert($restarted->read('virtual-football')['failure_count'] === 2, 'Retry state was lost across restart.');
    $assert($restarted->read('virtual-euro') === [], 'Worker states collided across leagues.');
    $preview = $newer;
    $preview['scope'] = 'preview';
    $preview['season_id'] = '201';
    $preview['observed_at'] = '2026-10-24T00:02:00+00:00';
    $store->save($preview);
    $repair = $newer;
    $repair['scope'] = 'reconciliation';
    $repair['observed_at'] = '2026-10-24T00:03:00+00:00';
    $store->save($repair);
    $checkpoint = $database->provider_checkpoints->findOne(['_id' => 'virtual-football']);
    $assert($checkpoint['capture_id'] === $second, 'Preview or historical repair replaced current-season pointer.');
    $assert($database->season_checkpoints->countDocuments() === 2, 'Per-season progress was not retained.');
    echo "Storage smoke passed: retry, chronology, immutability and league isolation.\n";
} finally {
    $database->drop();
}
