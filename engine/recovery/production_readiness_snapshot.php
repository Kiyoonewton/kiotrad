<?php
// Diagnostic reads only. Normal scenario GETs may populate rebuildable Redis caches.
try {
    require '/app/vendor/autoload.php';
    $app = require '/app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('kiotrad.local_data_mirror') || config('database.connections.mongodb.database') !== 'kiotrad') {
        throw new RuntimeException('Production kiotrad database required.');
    }
    if (!config('kiotrad.dashboard_async')) {
        throw new RuntimeException('Asynchronous dashboard required for a read-only capture.');
    }
    $read = function (string $route): array {
        $context = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents('http://web/api/v1/'.$route, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
        return ['http_status' => (int) ($status[1] ?? 0),
            'data' => $body === false ? null : json_decode($body, true)];
    };
    $started = gmdate(DATE_ATOM);
    $definition = app(App\Domain\Strategies\SimulationDefinitionSnapshot::class)->current();
    $ready = $read('ready');
    $overview = $read('overview');
    $view = $overview['data']['shared_simulation'] ?? [];
    $settings = ['capital' => $definition['capital_minor'] / 100,
        'capital_mode' => $view['capital_mode'] ?? null, 'profit_stop' => ($view['profit_stop_minor'] ?? 0) / 100,
        'qualification' => App\Domain\Strategies\ChampionMonitor::MIN_FIRST_WIN_DAY,
        'attempts' => $view['max_attempts'] ?? null, 'allowance' => $view['allowance_multiplier'] ?? null,
        'strategy' => 'all', 'league' => 'all'];
    $query = 'research/scenario?'.http_build_query($settings);
    $scenario = $read($query);
    $scenarioAgain = $read($query);
    $overviewAgain = $read('overview');
    $backup = new App\Infrastructure\Provider\DerivedViewBackup;
    $backups = [];
    foreach (['dashboard' => App\Infrastructure\Provider\DashboardSnapshot::class,
        'prepared' => App\Infrastructure\Provider\PreparedResearch::class] as $name => $class) {
        $key = (new ReflectionMethod($class, 'key'))->invoke(app($class));
        $saved = $backup->read($key);
        $backups[$name] = ['available' => is_array($saved),
            'generated_at' => $saved['generated_at'] ?? null, 'revision' => $saved['revision'] ?? null];
    }
    $db = Illuminate\Support\Facades\DB::connection('mongodb')->getDatabase();
    $store = new App\Infrastructure\History\ProductionHistoryStore;
    $imports = ['verified_seasons' => 0, 'failed_seasons' => 0, 'new_matches' => 0, 'new_prices' => 0,
        'complete_packages' => $db->production_history_imports->countDocuments(['kind' => 'package', 'status' => 'complete']),
        'unfinished_records' => $db->production_history_imports->countDocuments(['status' => ['$ne' => 'complete']])];
    $imports['expected_seasons'] = $imports['expected_matches'] = $imports['expected_prices'] = 0;
    $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'batchSize' => 5];
    foreach ($db->production_history_imports->find(['kind' => 'package', 'status' => 'complete'], $options) as $package) {
        $proof = $package['proof'];
        $imports['expected_seasons'] += count($proof['season_actions']);
        $imports['expected_matches'] += $proof['expected_matches'];
        $imports['expected_prices'] += $proof['expected_prices'];
        foreach ($proof['season_actions'] as $season => $action) {
            $key = $package['_id'].':'.$season;
            $record = $store->record($db, $key);
            try {
                if ($record === null || $record['status'] !== 'complete' || $record['proof'] !== $proof
                    || $record['plan']['action_sha256'] !== $action) {
                    throw new RuntimeException('Pinned season missing or differs.');
                }
                $store->verifyComplete($db, $key, $record);
                $imports['verified_seasons']++;
                $imports['new_matches'] += $record['plan']['new_matches'];
                $imports['new_prices'] += $record['plan']['new_prices'];
            } catch (Throwable) {
                $imports['failed_seasons']++;
            }
        }
    }
    echo json_encode(['version' => 'production-readiness-snapshot-1.0.0', 'started_at' => $started,
        'captured_at' => gmdate(DATE_ATOM), 'database' => config('database.connections.mongodb.database'),
        'definition' => $definition, 'settings' => $settings, 'readiness' => $ready,
        'overview' => $overview, 'overview_repeat' => $overviewAgain,
        'scenario' => $scenario, 'scenario_repeat' => $scenarioAgain, 'last_good' => $backups,
        'imports' => $imports], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    fwrite(STDERR, "Production readiness capture failed; no credentials shown.\n");
    exit(1);
}
