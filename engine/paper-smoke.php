<?php

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\MongoPaperStore;
use App\Infrastructure\Paper\PaperMarket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Paper smoke requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_paper_smoke']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    $db->drop();
    $ledger = app(PaperLedger::class);
    $store = new MongoPaperStore;
    $time = CarbonImmutable::now('UTC');
    $now = $time->toIso8601String();
    $initial = $ledger->open(547100, 1094200, $time->subMinute()->toIso8601String());
    $assert($store->create($initial), 'Initial paper configuration failed.');
    $assert(! $store->create($ledger->open(100000, null, $now)), 'Existing capital was reset.');
    $candidate = ['competition' => 'virtual-football', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
        'version' => '0.2.0-observation', 'status' => 'watching_fixture', 'team' => 'Synthetic', 'team_uid' => '1',
        'attempt' => 1, 'evidence_ready_at' => $time->subMinute()->toIso8601String(), 'next_match' => ['matchday' => 5]];
    $fact = ['fixture' => ['provider_match_id' => 'synthetic:paper', 'matchday' => 5, 'home_team_uid' => '1', 'away_team_uid' => '2',
        'scheduled_start_at' => $time->addMinutes(5)->toIso8601String(), 'status' => 'scheduled_or_unconfirmed', 'result' => null],
        'capture_id' => 'fixture', 'observed_at' => $now, 'price' => ['provider_match_id' => 'synthetic:paper',
            'pre_match_verified' => true, 'captured_at' => $now, 'prices' => ['H' => '1.5'], 'capture_id' => 'price']];
    $db->provider_captures->insertOne(['_id' => 'source-capture', 'competition' => 'virtual-football',
        'season_id' => '200', 'observed_at' => $now, 'matches' => [$fact['fixture']], 'odds_snapshots' => [$fact['price']]]);
    $db->provider_captures->insertOne(['_id' => 'future-capture', 'competition' => 'virtual-football',
        'season_id' => '200', 'observed_at' => $time->addMinute()->toIso8601String(), 'matches' => [], 'odds_snapshots' => []]);
    $facts = app(PaperMarket::class)->facts([['competition' => 'virtual-football',
        'season_id' => '200', 'team_uid' => '1', 'day' => 5]], $now);
    $assert($facts['virtual-football:200:1:5']['price']['capture_id'] === 'source-capture', 'Price provenance or as-of filtering failed.');
    $pending = $ledger->advance($initial, [$candidate], $facts, $now);
    $assert($store->replace(0, $pending), 'Paper reservation failed.');
    $assert(! $store->replace(0, $pending), 'Stale writer spent capital again.');
    $assert($store->read()['committed_minor'] === 20000, 'Stake was not reserved exactly once.');
    $before = $store->read();
    $overlap = $ledger->advance($before, [[...$candidate, 'competition' => 'virtual-euro']], [], $time->addMinutes(6)->toIso8601String());
    $assert($store->replace(1, $overlap), 'Pending-run journal updates were incorrectly treated as new bets.');
    $assert($store->read()['runs'][0]['status'] === 'overlap_skipped', 'Overlap was not skipped.');
    $fact['fixture']['status'] = 'settled';
    $fact['fixture']['result'] = 'H';
    $fact['observed_at'] = $time->addMinutes(7)->toIso8601String();
    $before = $store->read();
    $won = $ledger->advance($before, [], ['virtual-football:200:1:5' => $fact], $fact['observed_at']);
    $assert($store->replace(2, $won), 'Paper settlement failed.');
    $saved = $store->read();
    $assert($saved['cash_minor'] === 557100 && $saved['committed_minor'] === 0, 'Settlement cash is incorrect.');
    $assert($saved['decisions'] === $pending['decisions'], 'Original decision was rewritten.');
    $tampered = $saved;
    $tampered['decisions'][0]['stake_minor'] = 1;
    try {
        $store->replace(3, $tampered);
        throw new RuntimeException('Journal rewrite was accepted.');
    } catch (DomainException) {
        // Expected: preserve the original reservation evidence.
    }
    // A candidate calculated before kickoff cannot be committed after kickoff.
    $db->paper_portfolios->deleteMany([]);
    $assert($store->create($initial), 'Clock-test configuration failed.');
    $fact['fixture']['status'] = 'scheduled_or_unconfirmed';
    $fact['fixture']['result'] = null;
    $fact['fixture']['scheduled_start_at'] = $time->subSeconds(10)->toIso8601String();
    $fact['price']['captured_at'] = $time->subSeconds(30)->toIso8601String();
    $fact['observed_at'] = $time->subSeconds(25)->toIso8601String();
    $late = $ledger->advance($initial, [$candidate], ['virtual-football:200:1:5' => $fact], $time->subSeconds(20)->toIso8601String());
    $assert(! $store->replace(0, $late), 'Database accepted a reservation after kickoff.');
    $assert($store->read()['cash_minor'] === 547100, 'Rejected late reservation changed cash.');
    $fact['fixture']['scheduled_start_at'] = $time->addMinutes(5)->toIso8601String();
    $fact['price']['captured_at'] = $time->subSeconds(305)->toIso8601String();
    $expired = $ledger->advance($initial, [$candidate], ['virtual-football:200:1:5' => $fact], $time->subSeconds(20)->toIso8601String());
    $assert(! $store->replace(0, $expired), 'Database accepted a price that expired during calculation.');
    echo "Paper smoke passed: configuration, CAS, overlap, immutable decisions, settlement and kickoff deadline.\n";
} finally {
    $db->drop();
}
