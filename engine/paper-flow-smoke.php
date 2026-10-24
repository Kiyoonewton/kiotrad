<?php

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\MongoPaperStore;
use App\Infrastructure\Paper\PaperRunner;
use App\Infrastructure\Paper\PaperWorkerHealth;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\MongoCollectionStore;
use App\Infrastructure\Provider\MongoCollectorState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Disposable CI scenario. Synthetic provider observations; clock advances without waiting for real matches.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Paper flow smoke requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_paper_flow_smoke']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
try {
    $db->drop();
    $time = CarbonImmutable::now('UTC');
    $store = new MongoCollectionStore;
    $previous = ['competition' => 'virtual-football', 'season_id' => '100', 'scope' => 'reconciliation',
        'observed_at' => $time->subHour()->toIso8601String(), 'matches' => [], 'odds_snapshots' => [],
        'standings' => ['status' => 'verified_final', 'observed_at' => $time->subHour()->toIso8601String(),
            'champion' => ['team_uid' => '1', 'team_name' => 'Synthetic champion']]];
    $store->save($previous);
    $matches = [];
    foreach (['D', 'A', 'D', 'H', null] as $index => $result) {
        $matches[] = ['provider_match_id' => 'vf:match:'.(5001 + $index), 'season_id' => '200', 'matchday' => $index + 1,
            'home_team_uid' => '1', 'home_team' => 'Synthetic champion', 'away_team_uid' => (string) (2 + $index),
            'away_team' => 'Synthetic opponent '.($index + 1),
            'scheduled_start_at' => ($index === 4 ? $time->addMinute() : $time->subMinutes(20 - $index * 4))->toIso8601String(),
            'status' => $result === null ? 'scheduled_or_unconfirmed' : 'settled', 'result' => $result,
            'home_score' => $result === null ? null : ($result === 'H' ? 1 : 0),
            'away_score' => $result === null ? null : ($result === 'A' ? 1 : 0)];
    }
    $report = ['competition' => 'virtual-football', 'season_id' => '200', 'scope' => 'current',
        'observed_at' => $time->subSeconds(10)->toIso8601String(), 'matches' => $matches,
        'odds_snapshots' => [['provider_match_id' => 'vf:match:5005', 'market' => '1X2', 'market_status' => 1,
            'prices' => ['H' => '1.5', 'D' => '3.4', 'A' => '4.2'], 'pre_match_verified' => true,
            'captured_at' => $time->subSeconds(9)->toIso8601String()]]];
    $capture = $store->save($report);
    (new MongoCollectorState)->write('virtual-football', ['current_season_id' => '200', 'season_transition' => [
        'from_season_id' => '100', 'to_season_id' => '200', 'observed_at' => $time->subMinutes(21)->toIso8601String(),
        'continuity_status' => 'observed_rollover']]);
    $rows = app(ChampionMonitoring::class)->all();
    $candidate = array_values(array_filter($rows, fn ($row) => ($row['strategy_id'] ?? null) === 'champion-next-win'))[0];
    $assert($candidate['status'] === 'watching_fixture' && $candidate['trigger_day'] === 4, 'Provider history did not produce the qualifying observation.');
    $portfolio = new MongoPaperStore;
    $assert($portfolio->create(app(PaperLedger::class)->open(700000, 1400000, $time->subSeconds(30)->toIso8601String())), 'Paper configuration failed.');
    $runner = app(PaperRunner::class);
    $assert($runner->tick() === 'updated', 'Qualified observation did not create a paper entry.');
    $pending = $portfolio->read();
    $assert($pending['cash_minor'] === 680000 && $pending['committed_minor'] === 20000, 'Entry did not reserve NGN 200 from NGN 7,000.');
    $assert($pending['decisions'][0]['price_capture_id'] === $capture, 'Paper entry lost price provenance.');
    $assert($runner->tick() === 'waiting', 'Repeated poll duplicated a paper entry.');
    $health = app(PaperWorkerHealth::class);
    $health->record('checking');
    $health->record('completed', 'waiting');
    $assert($health->view()['status'] === 'active', 'Worker heartbeat was not persisted.');
    $assert($portfolio->read()['revision'] === $pending['revision'], 'Heartbeat changed portfolio revision.');
    $runner = app(PaperRunner::class); // New instance: resume solely from persisted data.
    CarbonImmutable::setTestNow($time->addMinutes(4));
    $report['observed_at'] = $time->addMinutes(3)->toIso8601String();
    $report['matches'][4] = [...$matches[4], 'status' => 'settled', 'result' => 'H', 'home_score' => 1, 'away_score' => 0];
    $report['odds_snapshots'] = [];
    $resultCapture = $store->save($report);
    $assert($runner->tick() === 'updated', 'Confirmed result did not settle the paper entry.');
    $settled = $portfolio->read();
    $assert($settled['cash_minor'] === 710000 && $settled['committed_minor'] === 0, 'Settlement should leave NGN 7,100 and no reservation.');
    $assert($settled['runs'][0]['net_minor'] === 10000, 'Paper run profit should be NGN 100.');
    $assert($settled['runs'][0]['attempts'][0]['result_capture_id'] === $resultCapture, 'Settlement lost result provenance.');
    $assert($settled['decisions'] === $pending['decisions'], 'Settlement rewrote the original decision.');
    $assert($runner->tick() === 'waiting', 'Repeated result observation credited twice.');
    Artisan::call('paper:status');
    $assert(str_contains(Artisan::output(), 'NGN 7,100.00'), 'Read-only status did not report the settled paper balance.');
    $assert($portfolio->read()['revision'] === $settled['revision'], 'Status command executed a paper mutation.');
    echo "Paper flow passed: observations -> trigger -> price -> reservation -> result -> NGN 100 net, with restart-safe retries.\n";
} finally {
    CarbonImmutable::setTestNow();
    $db->drop();
}
