<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class QualificationEmailWorker
{
    public function __construct(private ChampionMonitoring $monitoring, private PaperMarket $market,
        private QualificationEmailPolicy $policy, private QualificationEmailStore $store, private QualificationEmailSettings $settings, private AlertStakePlanner $planner) {}

    public function tick(): string
    {
        if (! ProductionAlertDelivery::allowed()) {
            return 'production_only';
        }
        if (! $this->configured()) {
            return 'disabled_or_unconfigured';
        }
        $settings = $this->settings->read();
        if (! $settings['enabled']) {
            return 'paused';
        }
        $sent = $uncertain = $missingStake = 0;
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
            $stakePlan = $this->planner->make($candidate, $fact, $settings, now('UTC')->toIso8601String());
            if (($stakePlan['status'] ?? '') !== 'estimated') {
                $missingStake++;

                continue; // Do not consume this run; retry inputs while its entry window remains open.
            }
            unset($alert['details']['Stake'], $alert['details']['Reference capital']);
            $alert['details'] += $this->planner->details($stakePlan);
            $alert['stake_plan'] = $stakePlan;
            if (CarbonImmutable::parse($stakePlan['first_quote']['price_observed_at'] ?? now('UTC')->toIso8601String())->lessThan(now('UTC')->subSeconds(60))) {
                $missingStake++;

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
                $this->send($alert['details']);
                $this->store->finish($alert['id'], $this->deliveryStatus());
                $sent++;
            } catch (Throwable) {
                $this->store->finish($alert['id'], 'delivery_uncertain');
                $uncertain++;
            }
        }

        return $uncertain ? 'delivery_uncertain:'.$uncertain : ($sent ? 'submitted:'.$sent : ($missingStake ? 'waiting_for_stake_inputs:'.$missingStake : 'waiting'));
    }

    protected function configured(): bool
    {
        return config('notifications.email.enabled') && filter_var(config('notifications.email.to'), FILTER_VALIDATE_EMAIL)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) && config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.host') && config('mail.mailers.smtp.username') && config('mail.mailers.smtp.password');
    }

    protected function send(array $details): void
    {
        Mail::to(config('notifications.email.to'))->send(new QualificationEmail($details));
    }

    protected function deliveryStatus(): string
    {
        return 'submitted_to_smtp';
    }
}
