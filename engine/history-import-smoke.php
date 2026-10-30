<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('History import smoke requires testing.');
}
config(['kiotrad.local_data_mirror' => true,
    'database.connections.mongodb.database' => 'kiotrad_local_20990101T000000Z']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$root = sys_get_temp_dir().'/history-smoke-'.bin2hex(random_bytes(8));
mkdir($root.'/package/files', 0700, true);
mkdir($root.'/archive');
$assert = function ($ok, $why) {
    if (! $ok) {
        throw new RuntimeException($why);
    }
};
try {
    $db->drop();
    $entries = [];
    foreach (['10', '11'] as $season) {
        $outcomes = $season === '10' ? [['id' => '1', 'odds' => '2', 'result' => 1],
            ['id' => '2', 'odds' => '3', 'result' => 0], ['id' => '3', 'odds' => '4', 'result' => 0]] : [];
        $body = json_encode(['queryUrl' => 'vfc_stats_round_odds2/vf:season:'.$season.'/1', 'doc' => [[
            'data' => ['odds' => [['id' => 'vf:match:'.$season, 'tournament_id' => 'vf:tournament:14149',
                'teams' => ['home' => ['id' => 'sr:competitor:1', 'name' => 'Synthetic home'],
                    'away' => ['id' => 'sr:competitor:2', 'name' => 'Synthetic away']],
                'market' => [['id' => 1, 'status' => -3, 'outcome' => $outcomes]],
            ]]],
        ]]], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);
        file_put_contents($root.'/package/files/'.$hash.'.json', $body);
        $entries[] = ['file' => $hash.'.json', 'sha256' => $hash, 'competition' => 'virtual-euro', 'season_id' => $season, 'matchday' => 1];
    }
    file_put_contents($root.'/package/plan.json', '{}');
    file_put_contents($root.'/package/manifest.json', json_encode(['package_version' => 'legacy-history-package-1.0.0',
        'plan_sha256' => hash('sha256', '{}'), 'files' => $entries], JSON_THROW_ON_ERROR));
    $match = ['provider_match_id' => 'vf:match:10', 'season_id' => '10', 'matchday' => 1,
        'home_team_uid' => '1', 'home_team' => 'Synthetic home', 'away_team_uid' => '2', 'away_team' => 'Synthetic away',
        'scheduled_start_at' => '2026-10-20T10:00:00Z', 'time_source' => 'stats_season_fixtures2.time.uts',
        'home_score' => 2, 'away_score' => 0, 'result' => 'H', 'status' => 'settled'];
    $db->provider_captures->insertOne(['_id' => 'original', 'competition' => 'virtual-euro', 'season_id' => '10',
        'observed_at' => '2026-10-20T10:04:00Z', 'matches' => [$match], 'odds_snapshots' => []]);
    $db->season_checkpoints->insertOne(['_id' => 'virtual-euro:10', 'season_id' => '10', 'capture_id' => 'original']);
    $db->provider_checkpoints->insertOne(['_id' => 'virtual-euro', 'capture_id' => 'original']);
    $args = ['folder' => $root.'/package', '--apply' => true, '--archive-root' => $root.'/archive'];
    $assert(Artisan::call('provider:import-legacy-history', $args) === 0, 'Initial local import failed: '.Artisan::output());
    $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
    $original = $db->provider_captures->findOne(['_id' => 'original'], $options);
    $assert($original['matches'][0] === $match, 'Original verified fixture was changed.');
    $new = $db->provider_captures->findOne(['season_id' => '11'], $options);
    $assert(count($new['matches']) === 1 && $new['matches'][0]['scheduled_start_at'] === null
        && $new['matches'][0]['result'] === null && $new['odds_snapshots'] === [], 'Partial source was fabricated or lost.');
    $priced = $db->provider_captures->findOne(['scope' => 'legacy_history_import', 'season_id' => '10'], $options);
    $assert($priced['matches'][0] === $match && count($priced['odds_snapshots']) === 1, 'Missing price did not preserve verified fixture.');
    $assert($db->season_checkpoints->findOne(['_id' => 'virtual-euro:10'])['capture_id'] === 'original', 'Existing checkpoint pointer changed.');
    $assert($db->provider_checkpoints->findOne(['_id' => 'virtual-euro'])['capture_id'] === 'original', 'Current provider pointer changed.');
    $assert(count(glob($root.'/archive/*.gz')) === 2, 'Raw responses were not archived.');
    $assert(Artisan::call('provider:import-legacy-history', $args) === 0 && $db->provider_captures->countDocuments() === 3, 'Retry duplicated captures.');
    // Simulate interruption after capture insert but before completion ledger update.
    $db->local_history_imports->updateMany([], ['$set' => ['status' => 'prepared']]);
    $assert(Artisan::call('provider:import-legacy-history', $args) === 0 && $db->provider_captures->countDocuments() === 3, 'Crash retry failed or duplicated captures.');
    $assert($db->local_history_imports->countDocuments(['status' => 'complete']) === 2, 'Crash retry did not complete.');
    // Seeded ledgers can survive raw reconstruction that removed a pointer.
    $db->season_checkpoints->deleteOne(['_id' => 'virtual-euro:11']);
    $db->provider_captures->deleteOne(['_id' => $new['_id']]);
    $assert(Artisan::call('provider:import-legacy-history', $args) === 0, 'Overlay did not restore missing materialization.');
    $assert($db->provider_captures->countDocuments() === 3, 'Overlay repair duplicated or lost captures.');
    $restored = $db->provider_captures->findOne(['_id' => $new['_id']], $options);
    $assert($restored === $new, 'Overlay repair changed the pinned original plan.');
    $assert($db->season_checkpoints->findOne(['_id' => 'virtual-euro:11'])['capture_id'] === $new['_id'], 'Missing history checkpoint not restored.');
    $assert($db->season_checkpoints->findOne(['_id' => 'virtual-euro:10'])['capture_id'] === 'original', 'Overlay repair replaced verified checkpoint.');
    $assert($db->paper_journal->countDocuments() === 0, 'Import changed paper trading journal.');
    echo 'History import passed: raw-first preservation, partial evidence, verified fields, idempotency and crash recovery.'.PHP_EOL;
} finally {
    $db->drop();
    foreach (glob($root.'/package/files/*') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($root.'/archive/*') ?: [] as $file) {
        unlink($file);
    }
    unlink($root.'/package/manifest.json');
    unlink($root.'/package/plan.json');
    rmdir($root.'/package/files');
    rmdir($root.'/package');
    rmdir($root.'/archive');
    rmdir($root);
}
