<?php

use App\Domain\Strategies\SimulationDefinitionSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Reviewed history smoke requires disposable testing.');
}
// Exercise the production guard against an isolated native database, never kiotrad.
$target = 'kiotrad_reviewed_smoke_'.bin2hex(random_bytes(6));
DB::extend('mongodb', fn ($config) => new Connection([...$config, 'database' => $target]));
config(['kiotrad.local_data_mirror' => false,
    'database.connections.mongodb.database' => 'kiotrad']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
if ($db->getDatabaseName() !== $target) {
    throw new RuntimeException('Smoke database isolation failed.');
}
$root = sys_get_temp_dir().'/reviewed-smoke-'.bin2hex(random_bytes(8));
mkdir($root.'/package/files', 0700, true);
$assert = function ($condition, $message) {
    if (! $condition) {
        throw new RuntimeException($message.' '.Artisan::output());
    }
};
$options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
try {
    $entries = $rows = [];
    foreach (['10', '11'] as $season) {
        $body = json_encode(['queryUrl' => 'vfc_stats_round_odds2/vf:season:'.$season.'/1', 'doc' => [[
            'data' => ['odds' => [['id' => 'vf:match:'.$season, 'tournament_id' => 'vf:tournament:14149',
                'teams' => ['home' => ['id' => 'sr:competitor:1', 'name' => 'Synthetic home'],
                    'away' => ['id' => 'sr:competitor:2', 'name' => 'Synthetic away']],
                'market' => [['id' => 1, 'status' => -3, 'outcome' => [
                    ['id' => '1', 'odds' => '2', 'result' => 1], ['id' => '2', 'odds' => '3', 'result' => 0],
                    ['id' => '3', 'odds' => '4', 'result' => 0],
                ]]],
            ]]],
        ]]], JSON_THROW_ON_ERROR);
        $sha = hash('sha256', $body);
        file_put_contents($root.'/package/files/'.$sha.'.json', $body);
        $entries[] = ['file' => $sha.'.json', 'sha256' => $sha, 'competition' => 'virtual-euro', 'season_id' => $season, 'matchday' => 1];
        $rows[] = ['league' => 'virtual-euro', 'season_id' => $season, 'fixture_id' => 'vf:match:'.$season,
            'fixture_action' => $season === '10' ? 'preserve_existing_fixture' : 'append_fixture_candidate',
            'price_action' => 'append_price_candidate'];
    }
    file_put_contents($root.'/package/plan.json', '{}');
    file_put_contents($root.'/package/manifest.json', json_encode(['package_version' => 'legacy-history-package-1.0.0',
        'plan_sha256' => hash('sha256', '{}'), 'files' => $entries], JSON_THROW_ON_ERROR));
    $snapshot = (new SimulationDefinitionSnapshot)->current();
    file_put_contents($root.'/review.json', json_encode(['version' => 'production-history-plan-1.0.0', 'applied' => false,
        'mode' => 'read_only_production_plan', 'blocked_seasons' => [], 'seasons' => [[], []], 'fixtures' => $rows, 'provenance' => [
            'inventory_completion' => ['applied' => false, 'seasons' => 2],
            'manifest_sha256' => hash_file('sha256', $root.'/package/manifest.json'), 'local_code_hashes' => array_reverse($snapshot['code_hashes'], true)],
        'strategy_alignment' => ['production_replay_version' => $snapshot['replay_version'], 'production_capital_minor' => $snapshot['capital_minor']],
        'fixture_action_counts' => ['append_fixture_candidate' => 1], 'price_action_counts' => ['append_price_candidate' => 2]], JSON_THROW_ON_ERROR));
    // Synthetic attestation tests proof linkage; the actual native backup restore has its own CI job.
    file_put_contents($root.'/backup.json', json_encode(['version' => 'production-pre-import-backup-1.0.0', 'complete' => true,
        'database_restore_verified' => true, 'production_writes' => false, 'production_database' => 'kiotrad',
        'plan_sha256' => hash_file('sha256', $root.'/review.json'), 'archives' => [['synthetic' => true]],
        'raw_evidence' => ['files' => 1], 'last_good_views' => [['synthetic' => true]]], JSON_THROW_ON_ERROR));
    $match = ['provider_match_id' => 'vf:match:10', 'season_id' => '10', 'matchday' => 1,
        'home_team_uid' => '1', 'home_team' => 'Synthetic home', 'away_team_uid' => '2', 'away_team' => 'Synthetic away',
        'scheduled_start_at' => '2026-10-18T10:00:00Z', 'time_source' => 'stats_season_fixtures2.time.uts',
        'home_score' => 2, 'away_score' => 0, 'result' => 'H', 'status' => 'settled'];
    $db->provider_captures->insertOne(['_id' => 'original', 'competition' => 'virtual-euro', 'season_id' => '10',
        'matches' => [$match], 'odds_snapshots' => []]);
    $db->season_checkpoints->insertOne(['_id' => 'virtual-euro:10', 'capture_id' => 'original']);
    $db->provider_checkpoints->insertOne(['_id' => 'virtual-euro', 'capture_id' => 'original']);
    $args = ['folder' => $root.'/package', 'plan' => $root.'/review.json', 'backup' => $root.'/backup.json'];
    $collections = iterator_to_array($db->listCollectionNames());
    $assert(Artisan::call('provider:import-reviewed-history', $args) === 0, 'Dry run failed.');
    $assert(iterator_to_array($db->listCollectionNames()) === $collections && $db->provider_captures->countDocuments() === 1, 'Dry run wrote data.');
    $apply = [...$args, '--apply' => true];
    $assert(Artisan::call('provider:import-reviewed-history', $apply) === 0, 'Apply failed.');
    $assert($db->provider_captures->countDocuments() === 3, 'Unexpected capture count.');
    $assert($db->provider_captures->findOne(['_id' => 'original'], $options)['matches'][0] === $match, 'Verified fixture changed.');
    $new = $db->provider_captures->findOne(['scope' => 'production_history_import', 'season_id' => '11'], $options);
    $assert($new['matches'][0]['scheduled_start_at'] === null && $new['matches'][0]['home_score'] === null, 'New timing or score fabricated.');
    $assert($new['odds_snapshots'][0]['pre_match_verified'] === false, 'Historical price presented as pre-match.');
    $assert($db->season_checkpoints->findOne(['_id' => 'virtual-euro:10'])['capture_id'] === 'original', 'Season pointer replaced.');
    $assert($db->provider_checkpoints->findOne(['_id' => 'virtual-euro'])['capture_id'] === 'original', 'Current pointer replaced.');
    $assert(Artisan::call('provider:import-reviewed-history', $apply) === 0, 'Retry failed.');
    $assert($db->provider_captures->countDocuments() === 3, 'Retry duplicated captures.');
    // Crash after capture insert, before checkpoint and ledger completion.
    $db->production_history_imports->updateMany(['kind' => 'season'], ['$set' => ['status' => 'prepared']]);
    $db->season_checkpoints->updateMany([], ['$unset' => ['production_history_keys' => '', 'odds_evidence_revision' => '']]);
    $assert(Artisan::call('provider:import-reviewed-history', $apply) === 0, 'Crash recovery failed.');
    foreach ($db->season_checkpoints->find() as $checkpoint) {
        $assert($checkpoint['odds_evidence_revision'] === 1, 'Revision applied twice.');
    }
    $db->provider_captures->updateOne(['_id' => $new['_id']], ['$set' => ['odds_snapshots.0.prices.H' => '9.99']]);
    $assert(Artisan::call('provider:import-reviewed-history', $apply) !== 0, 'Mutated immutable evidence accepted.');
    $assert($db->paper_journal->countDocuments() === 0, 'Paper balances changed.');
    echo 'Reviewed production history passed: dry run, append-only capture, pointer preservation, retry and crash recovery.'.PHP_EOL;
} finally {
    $db->drop();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
