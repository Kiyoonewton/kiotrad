<?php

namespace App\Http\Controllers;

use App\Infrastructure\Notifications\QualificationEmailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class QualificationEmailSettingsController extends Controller
{
    public function __invoke(Request $request, QualificationEmailSettings $store): JsonResponse
    {
        $token = (string) config('notifications.email.settings_token');
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 403);
        if ($request->isMethod('put')) {
            $settings = $request->validate([
                'enabled' => ['required', 'boolean'], 'capital' => ['required', 'integer', 'min:100', 'max:10000000'],
                'lead_seconds' => ['required', 'integer', 'min:5', 'max:600'],
                'league' => ['required', Rule::in(['all', ...array_column(config('kiotrad.competitions'), 'id')])],
                'next_win_enabled' => ['required', 'boolean'], 'next_season_enabled' => ['required', 'boolean'],
                'next_win_qualification' => ['required', 'integer', 'min:1', 'max:30'],
                'next_season_qualification' => ['required', 'integer', 'min:1', 'max:30'],
                'next_win_attempts' => ['required', 'integer', 'min:1', 'max:10'],
                'next_season_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            ]);
            foreach (['capital', 'lead_seconds', 'next_win_qualification', 'next_season_qualification', 'next_win_attempts', 'next_season_attempts'] as $name) {
                $settings[$name] = (int) $settings[$name];
            }
            foreach (['enabled', 'next_win_enabled', 'next_season_enabled'] as $name) {
                $settings[$name] = (bool) $settings[$name];
            }
            $settings = $store->save($settings);
        } else {
            $settings = $store->read();
        }

        return response()->json(['settings' => $settings, 'delivery_enabled' => (bool) config('notifications.email.enabled'),
            'smtp_configured' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.username') && (bool) config('mail.mailers.smtp.password')
                && filter_var(config('notifications.email.to'), FILTER_VALIDATE_EMAIL) !== false])->header('Cache-Control', 'no-store');
    }
}
