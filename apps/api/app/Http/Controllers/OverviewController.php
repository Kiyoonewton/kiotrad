<?php

namespace App\Http\Controllers;

use App\Domain\Strategies\StrategyCatalog;
use Illuminate\Http\JsonResponse;

final class OverviewController extends Controller
{
    public function __invoke(StrategyCatalog $strategies): JsonResponse
    {
        return response()->json([
            'schema_version' => '1',
            'application' => 'KioTrad',
            'mode' => 'foundation',
            'currency' => config('kiotrad.currency'),
            'display_timezone' => config('kiotrad.display_timezone'),
            'generated_at' => now('UTC')->toIso8601String(),
            'bankroll' => [
                'status' => 'not_configured',
                'starting_balance_minor' => null,
                'balance_minor' => null,
                'committed_minor' => null,
                'net_profit_minor' => null,
                'target_balance_minor' => null,
            ],
            'competitions' => array_map(fn (array $competition) => [
                ...$competition,
                'feed_status' => 'not_connected',
                'season_id' => null,
                'matchday' => null,
                'last_success_at' => null,
            ], config('kiotrad.competitions')),
            'strategies' => $strategies->all(),
            'active_run' => null,
            'capital_history' => [],
            'recent_runs' => [],
        ])->header('Cache-Control', 'no-store');
    }
}
