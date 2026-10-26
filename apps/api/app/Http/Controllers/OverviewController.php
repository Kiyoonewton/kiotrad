<?php

namespace App\Http\Controllers;

use App\Domain\Strategies\StrategyCatalog;
use App\Infrastructure\Paper\PaperStatus;
use App\Infrastructure\Paper\PaperWorkerHealth;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\DashboardSnapshot;
use App\Infrastructure\Provider\FeedStatus;
use App\Infrastructure\Provider\LocalMirrorStatus;
use App\Infrastructure\Provider\ResearchSummary;
use Illuminate\Http\JsonResponse;

final class OverviewController extends Controller
{
    public function __invoke(DashboardSnapshot $snapshot): JsonResponse
    {
        if (config('kiotrad.dashboard_async')) {
            return response()->json($snapshot->read())->header('Cache-Control', 'no-store');
        }

        return app()->call([$this, 'build']);
    }

    public function build(StrategyCatalog $strategies, FeedStatus $feeds, ChampionMonitoring $monitoring, PaperStatus $paper, PaperWorkerHealth $health, ResearchSummary $research): JsonResponse
    {
        $paperView = $paper->overview();

        return response()->json([
            'schema_version' => '1',
            'application' => 'KioTrad',
            'local_sync' => app(LocalMirrorStatus::class)->view(),
            'mode' => $paperView['bankroll']['status'] === 'paper' ? 'paper' : 'foundation',
            'currency' => config('kiotrad.currency'),
            'display_timezone' => config('kiotrad.display_timezone'),
            'generated_at' => now('UTC')->toIso8601String(),
            'competitions' => $feeds->all(),
            'strategies' => array_map(fn ($strategy) => [...$strategy, 'bankroll_enabled' => $paperView['bankroll']['status'] === 'paper'], $strategies->all()),
            'strategy_monitoring' => $monitoring->all(),
            'research_simulation' => $research->overview(),
            ...$paperView,
            'paper_worker' => $health->view(),
        ])->header('Cache-Control', 'no-store');
    }
}
