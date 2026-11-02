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
            'staking_model' => ['sometimes', 'required', Rule::in(['recovery', 'payout_carry_v1'])],
            'next_win_qualification' => ['sometimes', 'required', 'integer', 'min:1', 'max:30'],
            'next_season_qualification' => ['sometimes', 'required', 'integer', 'min:1', 'max:30'],
            'next_win_attempts' => ['sometimes', 'required', 'integer', 'min:1', 'max:10'],
            'next_season_attempts' => ['sometimes', 'required', 'integer', 'min:1', 'max:10'],
            'capital_mode' => ['sometimes', 'required', Rule::in(['compound', 'fixed'])],
            'profit_stop' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
            'qualification' => ['required', 'integer', 'min:1', 'max:30'],
            'attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'allowance' => ['required', 'integer', Rule::in([1, 2])],
            'strategy' => ['required', Rule::in(['all', ...array_column((new StrategyCatalog)->all(), 'id')])],
            'league' => ['required', Rule::in(['all', ...array_column(config('kiotrad.competitions'), 'id')])],
        ]);
        $settings += ['capital_mode' => 'compound', 'profit_stop' => 0];
        foreach (['profit_stop', 'capital', 'qualification', 'attempts', 'allowance', 'next_win_qualification', 'next_season_qualification', 'next_win_attempts', 'next_season_attempts'] as $name) {
            if (isset($settings[$name])) {
                $settings[$name] = (int) $settings[$name];
            }
        }
        $data = $research->scenario($settings);

        return response()->json($data, match ($data['status']) {
            'available' => 200, 'warming' => 202, default => 503,
        })->header('Cache-Control', 'no-store');
    }
}
