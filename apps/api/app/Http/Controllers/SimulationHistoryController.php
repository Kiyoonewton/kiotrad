<?php

namespace App\Http\Controllers;

use App\Infrastructure\Provider\SharedSimulationHistory;
use App\Infrastructure\Provider\SimulationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class SimulationHistoryController extends Controller
{
    public function __invoke(Request $request, SimulationHistory $history): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'competition' => ['sometimes', Rule::in(['all', ...array_keys(config('provider.competitions'))])]]);
        try {
            $page = $history->page((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10),
                $input['competition'] ?? 'all',
                empty($input['from']) ? null : CarbonImmutable::parse($input['from'], 'Africa/Lagos')->startOfDay()->utc()->toIso8601String(),
                empty($input['to']) ? null : CarbonImmutable::parse($input['to'], 'Africa/Lagos')->endOfDay()->utc()->toIso8601String());

            return response()->json(app(SharedSimulationHistory::class)->apply($page))->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable', 'message' => 'Simulation history could not be read.'], 503);
        }
    }
}
