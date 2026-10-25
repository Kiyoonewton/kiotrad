<?php

use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\DashboardSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Memory smoke requires testing environment.');
}
config(['database.connections.mongodb.database' => 'kiotrad_memory_smoke', 'kiotrad.simulation_cache_enabled' => false]);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
try {
    $db->drop();
    $matches = [];
    for ($i = 0; $i < 240; $i++) {
        $matches[] = ['provider_match_id' => 'memory:'.$i, 'home_team_uid' => '1', 'away_team_uid' => '2',
            'home_team' => 'Synthetic home', 'away_team' => 'Synthetic away', 'matchday' => intdiv($i, 8) + 1,
            'status' => 'settled', 'result' => 'H', 'scheduled_start_at' => '2026-10-25T00:00:00+00:00',
            'synthetic_padding' => str_repeat('x', 1024)];
    }
    for ($i = 0; $i < 300; $i++) {
        $db->provider_captures->insertOne(['_id' => 'synthetic-memory:'.$i, 'competition' => 'virtual-football',
            'season_id' => '100', 'observed_at' => '2026-10-25T00:00:00+00:00', 'matches' => $matches, 'odds_snapshots' => []]);
    }
    unset($matches);
    $rows = app(ChampionMonitoring::class)->forSeasons([['competition' => 'virtual-football', 'season_id' => '100']]);
    $assert(count($rows) === 2, 'Streaming dropped strategy rows.');
    $assert(memory_get_peak_usage(true) < 120 * 1024 * 1024, 'Replay retained too many full captures.');
    $code = <<<'CHILD'
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.mongodb.database'=>'kiotrad_memory_smoke']);
$worker = new class extends App\Infrastructure\Provider\DashboardSnapshot {
    protected function build(): array {
        $chunks=[];
        while(true) $chunks[]=str_repeat('x', 1048576);
    }
};
$worker->refresh();
CHILD;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code]);
    $process->setTimeout(30);
    $process->run();
    $assert(! $process->isSuccessful() && str_contains($process->getOutput().$process->getErrorOutput(), 'Allowed memory size'), 'Expected deliberate child-process memory fatal.');
    $worker = new class extends DashboardSnapshot
    {
        protected function build(): array
        {
            return ['generated_at' => now('UTC')->toIso8601String(), 'research_simulation' => ['status' => 'available'],
                'competitions' => [], 'strategy_monitoring' => []];
        }
    };
    $assert($worker->refresh() === 'refreshed', 'Memory fatal left the worker lease stuck.');
    echo "Replay memory smoke passed: streamed captures under 128 MB and released fatal worker lease.\n";
} finally {
    $db->drop();
}
