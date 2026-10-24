<?php

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\MongoPaperStore;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Paper\PaperRunner;
use App\Infrastructure\Provider\CollectionCycle;
use App\Infrastructure\Provider\CollectionGap;
use App\Infrastructure\Provider\CollectorTick;
use App\Infrastructure\Provider\MongoCollectionStore;
use App\Infrastructure\Provider\MongoCollectorState;
use App\Infrastructure\Provider\ResultRepairRequests;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Disposable MongoDB recovery scenario; observations and collector responses are synthetic.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Gap smoke requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_gap_smoke']);
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
    $now = $time->toIso8601String();
    $states = new MongoCollectorState;
    $states->write('virtual-football', ['current_season_id' => '200', 'collection_observed_at' => $now,
        'next_attempt_at' => $time->addSeconds(10)->toIso8601String()]);
    $fixture = ['provider_match_id' => 'vf:match:5005', 'season_id' => '200', 'matchday' => 5,
        'home_team_uid' => '1', 'home_team' => 'Synthetic', 'away_team_uid' => '2', 'away_team' => 'Opponent',
        'scheduled_start_at' => $time->addMinute()->toIso8601String(), 'status' => 'scheduled_or_unconfirmed', 'result' => null];
    $report = ['competition' => 'virtual-football', 'season_id' => '200', 'scope' => 'current', 'observed_at' => $now,
        'matches' => [$fixture], 'odds_snapshots' => [['provider_match_id' => 'vf:match:5005', 'pre_match_verified' => true,
            'captured_at' => $now, 'prices' => ['H' => '1.5', 'D' => '3.4', 'A' => '4.2']]]];
    $captures = new MongoCollectionStore;
    $captures->save($report);
    $candidate = ['competition' => 'virtual-football', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
        'version' => '0.2.0-observation', 'status' => 'watching_fixture', 'team' => 'Synthetic', 'team_uid' => '1',
        'attempt' => 1, 'next_match' => ['matchday' => 5], 'evidence_ready_at' => $now];
    $portfolio = new MongoPaperStore;
    $ledger = app(PaperLedger::class);
    $initial = $ledger->open(700000, 1400000, $now);
    $assert($portfolio->create($initial), 'Paper configuration failed.');
    $facts = app(PaperMarket::class)->facts([['competition' => 'virtual-football', 'season_id' => '200', 'team_uid' => '1', 'day' => 5]], $now);
    $pending = $ledger->advance($initial, [$candidate], $facts, $now);
    $assert($portfolio->replace(0, $pending), 'Initial reservation failed.');
    CarbonImmutable::setTestNow($time->addMinutes(6));
    $gap = (new CollectionGap)->detect('virtual-football', $states->read('virtual-football'), now('UTC')->toIso8601String(), false);
    $states->write('virtual-football', ['collection_gap' => $gap, 'failure_count' => 1]);
    $blocked = app(PaperMarket::class)->facts([['competition' => 'virtual-football', 'season_id' => '200', 'team_uid' => '1', 'day' => 5]], now('UTC')->toIso8601String());
    $assert($blocked['virtual-football:200:1:5']['collection_interrupted'], 'Interrupted collection was not exposed to paper entry checks.');
    $assert(app(PaperRunner::class)->tick() === 'waiting', 'An unconfirmed result changed the portfolio.');
    $requests = app(ResultRepairRequests::class);
    $assert($requests->pending('virtual-football') === ['200'], 'Missing paid-result repair was not persisted.');
    $assert(app(PaperRunner::class)->tick() === 'waiting', 'Retry duplicated a pending paper entry.');
    $assert($db->result_repair_requests->countDocuments() === 1, 'Repair retries were not deduplicated.');
    $assert($portfolio->read()['committed_minor'] === 20000, 'Gap released reserved cash prematurely.');
    $assert(! $requests->complete('virtual-football', '200'), 'Unconfirmed paid match was declared repaired.');
    $report['scope'] = 'reconciliation';
    $report['observed_at'] = now('UTC')->toIso8601String();
    $report['matches'][0] = [...$fixture, 'status' => 'settled', 'result' => 'H'];
    $report['odds_snapshots'] = [];
    $cycle = new class($report) extends CollectionCycle
    {
        public function __construct(private array $restored) {}

        public function run(string $competition, array $previous): array
        {
            if ((string) array_key_first($previous['pending_seasons']) !== '200') {
                throw new RuntimeException('Paid paper result was not prioritized.');
            }
            (new MongoCollectionStore)->save($this->restored);

            return ['current_season_id' => '201', 'collection_observed_at' => now('UTC')->toIso8601String(),
                'pending_seasons' => [], 'next_attempt_at' => now('UTC')->addMinute()->toIso8601String()];
        }
    };
    $app->instance(CollectionCycle::class, $cycle);
    $assert(app(CollectorTick::class)->run('virtual-football') === 'collected', 'Collector did not resume.');
    $assert($requests->pending('virtual-football') === [], 'Completed repair request was not acknowledged.');
    $state = (new MongoCollectorState)->read('virtual-football');
    $assert($state['collection_gap'] === null && $state['last_collection_gap']['id'] === $gap['id'], 'Gap did not survive recovery and restart.');
    $audit = $db->collection_gaps->findOne(['_id' => $gap['id']]);
    $assert($audit['expected_at'] === $gap['expected_at'] && $audit['status'] === 'collection_resumed', 'Gap audit lost its original expected time.');
    $assert(app(PaperRunner::class)->tick() === 'updated', 'Old-season confirmed result did not settle after a rollover.');
    $settled = $portfolio->read();
    $assert($settled['cash_minor'] === 710000 && $settled['committed_minor'] === 0, 'Late settlement arithmetic changed.');
    $assert($settled['decisions'] === $pending['decisions'], 'Recovery rewrote the original decision.');
    $assert(app(PaperRunner::class)->tick() === 'waiting', 'Late-result retry credited twice.');
    echo "Gap smoke passed: persisted outage, held funds, deduplicated repair, collector resumption and old-season settlement.\n";
} finally {
    CarbonImmutable::setTestNow();
    $db->drop();
}
