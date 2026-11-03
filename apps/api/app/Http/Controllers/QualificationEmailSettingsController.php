<?php

namespace App\Http\Controllers;

use App\Infrastructure\Notifications\ProductionAlertDelivery;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use App\Infrastructure\Notifications\QualificationSlackSettings;
use App\Infrastructure\Notifications\SlackTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class QualificationEmailSettingsController extends Controller
{
    public function __invoke(Request $request, QualificationEmailSettings $store): JsonResponse
    {
        $slack = $request->route('channel') === 'slack';
        $token = (string) (config('notifications.alert_settings_token') ?: config($slack ? 'notifications.slack_qualification.settings_token' : 'notifications.email.settings_token'));
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 403);
        if ($slack) {
            $store = app(QualificationSlackSettings::class);
        }
        if ($request->isMethod('put')) {
            $settings = $request->validate([
                'allowance' => ['sometimes', 'integer', Rule::in([1, 2])],
                'enabled' => ['required', 'boolean'], 'capital' => ['required', 'integer', 'min:100', 'max:10000000'],
                'lead_seconds' => ['required', 'integer', 'min:5', 'max:600'],
                'league' => ['required', Rule::in(['all', ...array_column(config('kiotrad.competitions'), 'id')])],
                'next_win_enabled' => ['required', 'boolean'], 'next_season_enabled' => ['required', 'boolean'],
                'next_win_qualification' => ['required', 'integer', 'min:1', 'max:30'],
                'next_season_qualification' => ['required', 'integer', 'min:1', 'max:30'],
                'next_win_attempts' => ['required', 'integer', 'min:1', 'max:10'],
                'next_season_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            ]);
            $settings += ['allowance' => 1];
            foreach (['allowance', 'capital', 'lead_seconds', 'next_win_qualification', 'next_season_qualification', 'next_win_attempts', 'next_season_attempts'] as $name) {
                $settings[$name] = (int) $settings[$name];
            }
            foreach (['enabled', 'next_win_enabled', 'next_season_enabled'] as $name) {
                $settings[$name] = (bool) $settings[$name];
            }
            $settings = $store->save($settings);
        } else {
            $settings = $store->read();
        }

        return response()->json(['settings' => $settings, 'production_delivery_allowed' => ProductionAlertDelivery::allowed(),
            'delivery_enabled' => ProductionAlertDelivery::allowed() && (bool) config($slack ? 'notifications.slack_qualification.enabled' : 'notifications.email.enabled'),
            'transport_configured' => $slack ? app(SlackTransport::class)->configured() : null,
            'smtp_configured' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.username') && (bool) config('mail.mailers.smtp.password')
                && filter_var(config('notifications.email.to'), FILTER_VALIDATE_EMAIL) !== false])->header('Cache-Control', 'no-store');
    }
}
