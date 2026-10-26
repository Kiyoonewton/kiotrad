<?php

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('CI-only raw rebuild check.');
}
config(['database.connections.mongodb.database' => 'kiotrad_local_20261026T000000Z', 'kiotrad.local_data_mirror' => true]);
Illuminate\Support\Facades\DB::purge('mongodb');
$db = Illuminate\Support\Facades\DB::connection('mongodb')->getDatabase();
$folder = sys_get_temp_dir().'/kiotrad-raw-'.bin2hex(random_bytes(6));
mkdir($folder);
$write = function (string $route, array $data, string $received) use ($folder): void {
    $body = json_encode(['doc' => [['data' => $data]]], JSON_THROW_ON_ERROR);
    file_put_contents($folder.'/'.hash('sha256', $route).'.json.gz', gzencode(json_encode([
        'url' => 'https://example.invalid/'.$route, 'requested_at' => $received, 'received_at' => $received,
        'http_status' => 200, 'body_sha256' => hash('sha256', $body), 'body_base64' => base64_encode($body),
    ], JSON_THROW_ON_ERROR)));
};
try {
    $db->drop();
    $write('uniquetournament_seasons/s-200', ['seasons' => [
        ['_id' => '100', '_utid' => 31867, 'start' => ['uts' => 1700000000]],
        ['_id' => '200', '_utid' => 31867, 'current' => true, 'start' => ['uts' => 1700010000]],
    ]], '2026-10-24T00:00:00Z');
    $write('stats_season_fixtures2/200', ['_id' => '200', '_utid' => 31867, 'matches' => [[
        '_id' => 99, '_seasonid' => 200, '_utid' => 31867, 'round' => 1, 'time' => ['uts' => 1700010000],
        'teams' => ['home' => ['uid' => 1, 'name' => 'Synthetic home'], 'away' => ['uid' => 2, 'name' => 'Synthetic away']],
        'periods' => ['ft' => ['home' => 1, 'away' => 0]],
    ]]], '2026-10-24T00:00:01Z');
    $write('vfc_stats_round_odds2/vf:season:200/1', ['odds' => [[
        'id' => 'vf:match:99', 'tournament_id' => 'vf:tournament:31867',
        'teams' => ['home' => ['id' => 'sr:competitor:1'], 'away' => ['id' => 'sr:competitor:2']],
        'market' => [['id' => 1, 'status' => -3, 'outcome' => [
            ['id' => '1', 'odds' => '1.5'], ['id' => '2', 'odds' => '3'], ['id' => '3', 'odds' => '5'],
        ]]],
    ]]], '2026-10-24T00:00:02Z');
    $counts = app(App\Infrastructure\Provider\RawEvidenceRebuilder::class)->rebuild($folder);
    $capture = $db->provider_captures->findOne(['scope' => 'raw_rebuild_odds']);
    if ($counts['odds'] !== 1 || $counts['fixtures'] !== 1 || $db->provider_catalog_links->countDocuments() !== 1
        || $capture['matches'][0]['result'] !== 'H' || $capture['odds_snapshots'][0]['pre_match_verified'] !== false
        || $capture['odds_snapshots'][0]['captured_at'] !== '2026-10-24T00:00:02+00:00'
        || $capture['matches'][0]['scheduled_start_at'] === $capture['observed_at']
        || $db->provider_checkpoints->countDocuments() !== 1) {
        throw new RuntimeException('Raw replay lost identity, timing, prices or catalog history.');
    }
    echo "Raw rebuild passed: source-only reconstruction preserves timing, prices and links.\n";
} finally {
    $db->drop();
    foreach (glob($folder.'/*') as $path) {
        unlink($path);
    }
    rmdir($folder);
}
