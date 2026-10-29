<?php

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\MongoPaperStore;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\FixtureBaselineStore;
use App\Infrastructure\Provider\MongoCollectionStore;
use App\Infrastructure\Provider\MongoCollectorState;
use App\Infrastructure\Provider\PreparedResearch;
use App\Infrastructure\Provider\ResearchSummary;
use App\Infrastructure\Provider\SimulationCache;
use App\Infrastructure\Provider\SimulationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Event simulation smoke requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_event_smoke', 'kiotrad.simulation_cache_enabled' => true]);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
try {
    $db->drop();
    $now = CarbonImmutable::now('UTC');
    CarbonImmutable::setTestNow($now);
    $base = $now->subHour();
    $store = new MongoCollectionStore;
    $store->save(['competition' => 'virtual-football', 'season_id' => '100', 'scope' => 'reconciliation',
        'observed_at' => $now->subSeconds(10)->toIso8601String(), 'matches' => [], 'odds_snapshots' => [],
        'standings' => ['status' => 'verified_final', 'observed_at' => $now->subSeconds(10)->toIso8601String(),
            'champion' => ['team_uid' => '1', 'team_name' => 'Synthetic champion']]]);
    $matches = [];
    foreach (['D', 'A', 'D', 'A', 'H', 'H', null] as $i => $result) {
        $matches[] = ['provider_match_id' => 'synthetic:'.$i, 'season_id' => '200', 'matchday' => $i + 1,
            'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Synthetic champion', 'away_team' => 'Synthetic opponent',
            'scheduled_start_at' => $base->addMinutes($i * 4)->toIso8601String(),
            'status' => $result === null ? 'scheduled_or_unconfirmed' : 'settled', 'result' => $result];
    }
    $prices = array_map(fn ($i) => ['provider_match_id' => 'synthetic:'.$i, 'captured_at' => $now->subSeconds(5)->toIso8601String(),
        'pre_match_verified' => false, 'prices' => ['H' => '1.5']], [5, 6]);
    $report = ['competition' => 'virtual-football', 'season_id' => '200', 'scope' => 'current',
        'observed_at' => $now->subSeconds(3)->toIso8601String(), 'matches' => $matches, 'odds_snapshots' => $prices];
    $original = $store->save($report);
    (new MongoCollectorState)->write('virtual-football', ['current_season_id' => '200',
        'collection_observed_at' => $now->subSecond()->toIso8601String(), 'season_catalog' => [
            ['season_id' => '100', 'start_uts' => $base->subHours(2)->timestamp],
            ['season_id' => '200', 'start_uts' => $base->timestamp],
        ]]);
    $assert($db->provider_catalog_links->countDocuments() === 1, 'Provider season adjacency was not retained.');
    $portfolio = new MongoPaperStore;
    $portfolio->create(app(PaperLedger::class)->open(700000, 1400000, $now->subSeconds(30)->toIso8601String()));
    $before = $portfolio->read();
    $monitor = app(ChampionMonitoring::class);
    $find = fn ($rows) => array_values(array_filter($rows, fn ($r) => ($r['strategy_id'] ?? '') === 'champion-next-win'))[0];
    $recovered = $find($monitor->forSeason('virtual-football', '200'));
    $assert($recovered['status'] === 'observed_win' && $recovered['recovered'], 'Delayed evidence still blocked research simulation.');
    $assert($recovered['simulation']['net_minor'] === 10000, 'Recovered win did not calculate NGN 100 profit.');
    config(['kiotrad.simulation_cache_enabled' => false]);
    $batched = $find($monitor->forSeasons([['competition' => 'virtual-football', 'season_id' => '200']]));
    $assert($batched['simulation'] === $recovered['simulation'], 'Batched replay changed financial results or provenance.');
    config(['kiotrad.simulation_cache_enabled' => true]);
    $strict = $find($monitor->all(false));
    $assert($strict['status'] === 'waiting_for_history', 'Catalog-only history enabled a backdated journal entry.');
    $history = app(SimulationHistory::class);
    $page = $history->page(1, 1, 'virtual-football');
    $assert($page['pagination']['total'] === 2 && $find($page['rows'])['season_id'] === '200', 'Event history pagination selected wrong season.');
    $assert($history->page(2, 1, 'virtual-football')['rows'][0]['season_id'] === '100', 'Older history page missing.');
    $period = $history->seasons('virtual-football', $base->addMinutes(15)->toIso8601String(), $base->addMinutes(21)->toIso8601String());
    $assert(count($period) === 1 && $period[0]['season_id'] === '200', 'Event-date filter excluded a season overlapping the requested period.');
    $cache = app(SimulationCache::class);
    $calls = 0;
    $compute = function () use (&$calls) {
        return ['calls' => ++$calls];
    };
    foreach (['week' => SimulationCache::RECENT_TTL, 'day' => SimulationCache::HISTORICAL_TTL] as $key => $ttl) {
        $cache->remember($key, 'v1', $ttl, $compute);
        $logical = 'simulation:'.hash('sha256', 'kiotrad_event_smoke:'.$key.':'.$ttl);
        $prefix = Cache::store('redis')->getStore()->getPrefix();
        $remaining = Redis::connection('cache')->ttl($prefix.$logical);
        $assert($remaining > $ttl - 10 && $remaining <= $ttl, 'Redis retained the wrong week/day TTL.');
    }
    $first = $cache->remember('day', 'v1', 86400, $compute);
    $assert($first['calls'] === 2 && $calls === 2, 'Redis did not reuse historical calculation.');
    $report['observed_at'] = $now->addSeconds(2)->toIso8601String();
    $report['matches'][5]['result'] = 'D';
    $report['matches'][6]['result'] = 'H';
    $report['matches'][6]['status'] = 'settled';
    $store->save($report);
    CarbonImmutable::setTestNow($now->addSeconds(5));
    $updated = $find($monitor->forSeason('virtual-football', '200'));
    $assert($updated['simulation']['net_minor'] === 20000, 'Repaired source data did not invalidate cached calculations.');
    $assert($updated['simulation']['total_staked_minor'] === 100000, 'Recovery did not include both simulated stakes.');
    $summary = app(ResearchSummary::class)->build();
    $assert($summary['status'] === 'available' && $summary['runs'][0]['net_minor'] === 20000, 'Overview research summary did not reflect repaired data.');
    $snapshot = app(DashboardSnapshot::class);
    $assert($snapshot->refresh() === 'refreshed', 'Background dashboard could not publish real Redis snapshot.');
    $assert($snapshot->read()['dashboard_cache']['status'] === 'ready', 'Published dashboard snapshot unavailable.');
    $assert($portfolio->read() === $before, 'Research replay modified the shared journal.');
    $assert($db->provider_captures->findOne(['_id' => $original])['matches'][5]['result'] === 'H', 'Recovery rewrote an immutable capture.');
    // Interactive scenarios reuse one prepared Redis frame, never mutate provider/journal data.
    $prepared = app(PreparedResearch::class);
    $assert($prepared->refresh() === 'prepared', 'Reusable scenario evidence was not prepared.');
    $countBefore = $db->provider_captures->countDocuments();
    $settings = ['capital' => 50000, 'qualification' => 5, 'attempts' => 2, 'allowance' => 1, 'strategy' => 'all', 'league' => 'all'];
    $scenario = $prepared->scenario($settings);
    $assert($scenario['status'] === 'available' && $scenario['scenario_cache'] === 'miss', 'Prepared scenario did not calculate.');
    $assert($prepared->scenario($settings)['scenario_cache'] === 'hit', 'Identical scenario did not reuse Redis.');
    $stricter = $prepared->scenario([...$settings, 'qualification' => 6, 'allowance' => 2]);
    $assert($stricter['runs'] === [] && $stricter['allowance_multiplier'] === 2, 'Scenario settings leaked or qualification was ignored.');
    $assert($db->provider_captures->countDocuments() === $countBefore && $portfolio->read() === $before,
        'Scenario calculation modified provider evidence or paper journal.');
    // Cache eviction must not blank an already prepared dashboard or Simulator.
    $dashboardKey = (new ReflectionMethod(DashboardSnapshot::class, 'key'))->invoke($snapshot);
    $preparedKey = (new ReflectionMethod(PreparedResearch::class, 'key'))->invoke($prepared);
    Cache::store('redis')->forget($dashboardKey);
    Cache::store('redis')->forget($preparedKey);
    $assert($snapshot->read()['dashboard_cache']['source'] === 'last_good_backup', 'Dashboard backup unavailable after Redis eviction.');
    $assert($prepared->scenario($settings)['status'] === 'available', 'Simulator backup unavailable after Redis eviction.');
    $assert($prepared->refresh() === 'unchanged', 'Prepared backup could not restore Redis without replay.');
    $baseline = app(FixtureBaselineStore::class);
    $assert($baseline->refresh('virtual-football')['updated_seasons'] === 0, 'Unchanged seasons were folded again.');
    $minimum = $db->fixture_odds_minima->findOne(['competition' => 'virtual-football']);
    $assert(($minimum['minima']['H']['odds_scaled'] ?? null) === 15000, 'MongoDB minima reduction changed the floor.');
    echo "Event simulation passed: recovered result, historical pagination, real Redis TTLs, source invalidation, summary and immutable journal.\n";
} finally {
    CarbonImmutable::setTestNow();
    $db->drop();
}
