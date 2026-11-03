<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class QualificationEmailWorker
{
    public function __construct(private ChampionMonitoring $monitoring, private PaperMarket $market,
        private QualificationEmailPolicy $policy, private QualificationEmailStore $store, private QualificationEmailSettings $settings) {}

    public function tick(): string
    {
        $recipient = config('notifications.email.to');
        if (! config('notifications.email.enabled') || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || ! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)
            || config('mail.default') !== 'smtp' || ! config('mail.mailers.smtp.host')
            || ! config('mail.mailers.smtp.username') || ! config('mail.mailers.smtp.password')) {
            return 'disabled_or_unconfigured';
        }
        $settings = $this->settings->read();
        if (! $settings['enabled']) {
            return 'paused';
        }
        $sent = $uncertain = 0;
        foreach ($this->monitoring->forEmail($settings) as $candidate) {
            $alert = $this->policy->prepare($candidate, now('UTC')->toIso8601String(), $settings);
            if ($alert === null) {
                continue;
            }
            $request = ['competition' => $candidate['competition'], 'season_id' => $candidate['season_id'],
                'team_uid' => $candidate['team_uid'], 'day' => $candidate['next_match']['matchday']];
            $fact = $this->market->facts([$request], now('UTC')->toIso8601String())[implode(':', array_values($request))] ?? [];
            if (($fact['collection_interrupted'] ?? true) || ! isset($fact['observed_at'])
                || CarbonImmutable::parse($fact['observed_at'])->lessThan(now('UTC')->subSeconds(60))
                || CarbonImmutable::parse($fact['observed_at'])->greaterThan(now('UTC'))
                || ($fact['fixture']['scheduled_start_at'] ?? '') !== $candidate['next_match']['scheduled_start_at']) {
                continue;
            }
            if ($this->policy->prepare($candidate, now('UTC')->toIso8601String(), $settings) === null || ! $this->store->claim($alert)) {
                continue;
            }
            if (now('UTC')->greaterThanOrEqualTo($alert['deadline'])) {
                $this->store->finish($alert['id'], 'expired');

                continue;
            }
            try {
                Mail::to($recipient)->send(new QualificationEmail($alert['details']));
                $this->store->finish($alert['id'], 'submitted_to_smtp');
                $sent++;
            } catch (Throwable) {
                $this->store->finish($alert['id'], 'delivery_uncertain');
                $uncertain++;
            }
        }

        return $uncertain ? 'delivery_uncertain:'.$uncertain : ($sent ? 'submitted:'.$sent : 'waiting');
    }
}
