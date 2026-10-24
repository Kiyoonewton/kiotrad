<?php

namespace App\Http\Controllers;

use App\Domain\Strategies\StrategyCatalog;
use App\Infrastructure\Paper\PaperStatus;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\FeedStatus;
use Illuminate\Http\JsonResponse;

final class OverviewController extends Controller
{
    public function __invoke(StrategyCatalog $strategies, FeedStatus $feeds, ChampionMonitoring $monitoring, PaperStatus $paper): JsonResponse
    {
        $paperView = $paper->overview();

        return response()->json([
            'schema_version' => '1',
            'application' => 'KioTrad',
            'mode' => $paperView['bankroll']['status'] === 'paper' ? 'paper' : 'foundation',
            'currency' => config('kiotrad.currency'),
            'display_timezone' => config('kiotrad.display_timezone'),
            'generated_at' => now('UTC')->toIso8601String(),
            'competitions' => $feeds->all(),
            'strategies' => array_map(fn ($strategy) => [...$strategy, 'bankroll_enabled' => $paperView['bankroll']['status'] === 'paper'], $strategies->all()),
            'strategy_monitoring' => $monitoring->all(),
            ...$paperView,
        ])->header('Cache-Control', 'no-store');
    }
}
