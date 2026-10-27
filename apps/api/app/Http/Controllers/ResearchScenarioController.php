<?php

namespace App\Http\Controllers;

use App\Domain\Strategies\StrategyCatalog;
use App\Infrastructure\Provider\PreparedResearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ResearchScenarioController extends Controller
{
    public function __invoke(Request $request, PreparedResearch $research): JsonResponse
    {
        $settings = $request->validate([
            'capital' => ['required', 'integer', 'min:100', 'max:10000000'],
            'qualification' => ['required', 'integer', 'min:1', 'max:30'],
            'attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'allowance' => ['required', 'integer', Rule::in([1, 2])],
            'strategy' => ['required', Rule::in(['all', ...array_column((new StrategyCatalog)->all(), 'id')])],
            'league' => ['required', Rule::in(['all', ...array_column(config('kiotrad.competitions'), 'id')])],
        ]);
        foreach (['capital', 'qualification', 'attempts', 'allowance'] as $name) {
            $settings[$name] = (int) $settings[$name];
        }
        $data = $research->scenario($settings);

        return response()->json($data, match ($data['status']) {
            'available' => 200, 'warming' => 202, default => 503,
        })->header('Cache-Control', 'no-store');
    }
}
