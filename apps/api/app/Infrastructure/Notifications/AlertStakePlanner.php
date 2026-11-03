<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Alerts\EntryStakePlan;
use App\Domain\Paper\WinQuote;
use Carbon\CarbonImmutable;
use DomainException;

class AlertStakePlanner
{
    public function __construct(private AlertStakeEvidence $evidence) {}

    public function make(array $candidate, array $fact, array $settings, string $asOf): array
    {
        $prefix = $candidate['strategy_id'] === 'champion-next-win' ? 'next_win' : 'next_season';
        $fixtures = $this->evidence->window($candidate, $settings[$prefix.'_attempts'], $asOf);
        if (count($fixtures) !== $settings[$prefix.'_attempts']) {
            return ['status' => 'missing_schedule_or_historical_odds'];
        }
        $fixture = $fact['fixture'];
        if ($fixtures[0]['provider_match_id'] !== ($fixture['provider_match_id'] ?? null)
            || $fixtures[0]['scheduled_start_at'] !== $fixture['scheduled_start_at']
            || $fixtures[0]['home_team_uid'] !== ($fixture['home_team_uid'] ?? null)
            || $fixtures[0]['away_team_uid'] !== ($fixture['away_team_uid'] ?? null)) {
            return ['status' => 'fixture_changed'];
        }
        $price = $fact['price'] ?? null;
        if (! $price || CarbonImmutable::parse($price['captured_at'])->lessThan(CarbonImmutable::parse($asOf)->subSeconds(60))) {
            return ['status' => 'missing_or_stale_current_price'];
        }
        try {
            $quote = (new WinQuote)->make($fixture, $price, $candidate['team_uid'], 0, 1, 1000000000, $asOf);
        } catch (DomainException $error) {
            return ['status' => $error->getMessage()];
        }
        $fixtures[0]['current_odds_scaled'] = $quote['odds_scaled'];
        $plan = (new EntryStakePlan)->calculate($fixtures, $settings['capital'] * 100, $settings['allowance'] ?? 1);

        return [...$plan, 'as_of' => $asOf, 'fixtures' => $fixtures,
            'first_quote' => array_intersect_key($quote, array_flip(['odds', 'odds_scaled', 'price_capture_id', 'price_observed_at']))];
    }

    public function details(array $stakePlan): array
    {
        $money = fn (int $minor) => OpportunityNotifications::money($minor);
        $details = ['Stake model' => EntryStakePlan::VERSION.' — frozen conditional plan',
            'Run capital' => $money($stakePlan['capital_minor']).' configured, not verified bookmaker balance',
            'Allowance' => '×'.$stakePlan['allowance_multiplier'], 'Frozen target' => $money($stakePlan['profit_target_minor']),
            'Planned total spend' => $money($stakePlan['plan']['total_minor']), 'Required allowance' => $money($stakePlan['allowance_minor'])];
        foreach ($stakePlan['plan']['attempts'] as $i => $attempt) {
            $fixture = $stakePlan['fixtures'][$i];
            $price = number_format($attempt['estimated_odds_scaled'] / 10000, 4, '.', '');
            $details['Attempt '.($i + 1)] = 'D'.$fixture['matchday'].' '.$fixture['home_team'].' vs '.$fixture['away_team'].'; stake '.$money($attempt['stake_minor'])
                .'; minimum estimated odds '.$price.'; '.CarbonImmutable::parse($fixture['scheduled_start_at'])->setTimezone('Africa/Lagos')->format('d/m H:i:s').' WAT'
                .($i === 0 ? '; current quote '.$stakePlan['first_quote']['odds'] : '; later actual odds unknown');
        }
        $details['Instructions'] = 'Stop at first win. Do not increase these frozen stakes when later odds rise. If odds fall below the row estimate, market closes or funds are insufficient, stop and reassess. Confirm each bet manually; no capital is reserved by alerts.';
        $details['Estimate basis'] = 'Earlier-season exact home/away fixture minimum known by alert time. Future potential payouts use estimates; no future actual odds/results are used. Historical minima are not guaranteed price floors.';

        return $details;
    }
}
