<?php

use App\Infrastructure\Provider\ArchivedFeed;
use App\Infrastructure\Provider\LegacyOddsSource;
use App\Infrastructure\Provider\RawEvidenceRebuilder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('CI-only raw rebuild check.');
}
config(['database.connections.mongodb.database' => 'kiotrad_local_20261026T000000Z', 'kiotrad.local_data_mirror' => true]);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
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
    $counts = app(RawEvidenceRebuilder::class)->rebuild($folder);
    $capture = $db->provider_captures->findOne(['scope' => 'raw_rebuild_odds']);
    if ($counts['odds'] !== 1 || $counts['fixtures'] !== 1 || $db->provider_catalog_links->countDocuments() !== 1
        || $capture['matches'][0]['result'] !== 'H' || $capture['odds_snapshots'][0]['pre_match_verified'] !== false
        || $capture['odds_snapshots'][0]['captured_at'] !== '2026-10-24T00:00:02+00:00'
        || $capture['matches'][0]['scheduled_start_at'] === $capture['observed_at']
        || $db->provider_checkpoints->countDocuments() !== 1) {
        throw new RuntimeException('Raw replay lost identity, timing, prices or catalog history.');
    }
    // Legacy plain files keep unknown transport times, but must survive source-only reconstruction.
    $oddsPath = $folder.'/'.hash('sha256', 'vfc_stats_round_odds2/vf:season:200/1').'.json.gz';
    $legacyPayload = app(ArchivedFeed::class)->read($oddsPath)['feed']->payload;
    unlink($oddsPath);
    $legacyPayload['queryUrl'] = 'vfc_stats_round_odds2/vf:season:200/1';
    $legacyPayload['doc'][0]['data']['odds'][0]['market'][0]['outcome'][0]['odds'] = '2.50';
    $decoder = app(LegacyOddsSource::class);
    $source = $decoder->decode(json_encode($legacyPayload), 'legacy.json', '2026-10-26T10:00:00Z');
    $source['provenance']['accepted_match_ids'] = ['vf:match:99'];
    file_put_contents($folder.'/legacy.json.gz', gzencode(json_encode($decoder->envelope($source))));
    $legacyPayload['doc'][0]['data']['odds'][0]['market'][0]['outcome'][0]['odds'] = '2.60';
    $conflict = $decoder->decode(json_encode($legacyPayload), 'conflict.json', '2026-10-26T10:00:01Z');
    $conflict['provenance']['accepted_match_ids'] = [];
    file_put_contents($folder.'/conflict.json.gz', gzencode(json_encode($decoder->envelope($conflict))));
    $db->drop();
    app(RawEvidenceRebuilder::class)->rebuild($folder);
    $quotes = [];
    foreach ($db->provider_captures->find() as $row) {
        foreach ($row['odds_snapshots'] ?? [] as $price) {
            $quotes[] = $price;
        }
    }
    if (count($quotes) !== 1 || $quotes[0]['prices']['H'] !== '2.50'
        || $quotes[0]['provenance']['original_received_at'] !== null
        || $quotes[0]['provenance']['origin'] !== 'legacy_file'
        || $quotes[0]['captured_at'] !== '2026-10-26T10:00:00+00:00') {
        throw new RuntimeException('Legacy raw reconstruction lost provenance or reintroduced rejected prices.');
    }
    echo "Raw rebuild passed: source-only reconstruction preserves timing, prices and links.\n";
} finally {
    $db->drop();
    foreach (glob($folder.'/*') as $path) {
        unlink($path);
    }
    rmdir($folder);
}
