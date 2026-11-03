<?php

namespace App\Http\Controllers;

use App\Infrastructure\Notifications\ProductionAlertDelivery;
use App\Infrastructure\Notifications\QualificationAlertSettings;
use App\Infrastructure\Notifications\SlackTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class QualificationAlertSettingsController extends Controller
{
    public function __invoke(Request $request, QualificationAlertSettings $store): JsonResponse
    {
        $token = config('notifications.alert_settings_token') ?: (config('notifications.email.settings_token') ?: config('notifications.slack_qualification.settings_token'));
        abort_unless(is_string($token) && strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 403);
        $view = $store->view();
        if ($request->isMethod('put')) {
            $settings = $request->validate([
                'enabled' => ['required', 'boolean'], 'email_enabled' => ['required', 'boolean'], 'slack_enabled' => ['required', 'boolean'],
                'capital' => ['required', 'integer', 'min:100', 'max:10000000'], 'allowance' => ['required', 'integer', Rule::in([1, 2])],
                'lead_seconds' => ['required', 'integer', 'min:5', 'max:600'],
                'league' => ['required', Rule::in(['all', ...array_column(config('kiotrad.competitions'), 'id')])],
                'next_win_enabled' => ['required', 'boolean'], 'next_season_enabled' => ['required', 'boolean'],
                'next_win_qualification' => ['required', 'integer', 'min:1', 'max:30'], 'next_season_qualification' => ['required', 'integer', 'min:1', 'max:30'],
                'next_win_attempts' => ['required', 'integer', 'min:1', 'max:10'], 'next_season_attempts' => ['required', 'integer', 'min:1', 'max:10'],
                'migration_source' => [Rule::requiredIf($view['needs_review']), Rule::in(['email', 'slack'])],
            ]);
            unset($settings['migration_source']);
            foreach ($settings as $key => &$value) {
                if (str_ends_with($key, 'enabled') || $key === 'enabled') {
                    $value = (bool) $value;
                } elseif ($key !== 'league') {
                    $value = (int) $value;
                }
            }
            unset($value);
            $view['settings'] = $store->save($settings);
            $view['needs_review'] = false;
        }
        $production = ProductionAlertDelivery::allowed();
        $smtp = config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') && config('mail.mailers.smtp.username')
            && config('mail.mailers.smtp.password') && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)
            && filter_var(config('notifications.email.to'), FILTER_VALIDATE_EMAIL);

        return response()->json([...$view, 'production_delivery_allowed' => $production,
            'channels' => ['email' => ['delivery_enabled' => $production && (bool) config('notifications.email.enabled'), 'configured' => (bool) $smtp],
                'slack' => ['delivery_enabled' => $production && (bool) config('notifications.slack_qualification.enabled'), 'configured' => app(SlackTransport::class)->configured()]]])
            ->header('Cache-Control', 'no-store');
    }
}
