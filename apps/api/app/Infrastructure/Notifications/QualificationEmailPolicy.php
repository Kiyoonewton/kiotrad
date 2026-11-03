<?php

namespace App\Infrastructure\Notifications;

use Carbon\CarbonImmutable;

final class QualificationEmailPolicy
{
    public function prepare(array $candidate, string $now, array $settings = []): ?array
    {
        if (($candidate['status'] ?? '') !== 'watching_fixture' || ($candidate['attempt'] ?? 0) !== 1
            || empty($candidate['team_uid']) || empty($candidate['next_match']['scheduled_start_at'])) {
            return null;
        }
        $prefix = $candidate['strategy_id'] === 'champion-next-win' ? 'next_win' : 'next_season';
        if (($settings[$prefix.'_enabled'] ?? true) === false
            || (($settings['league'] ?? 'all') !== 'all' && $settings['league'] !== $candidate['competition'])
            || ($candidate['trigger_day'] ?? 0) < ($settings[$prefix.'_qualification'] ?? 5)) {
            return null;
        }
        $start = CarbonImmutable::parse($candidate['next_match']['scheduled_start_at']);
        $deadline = $start->subSeconds(max(5, (int) ($settings['lead_seconds'] ?? config('notifications.email.lead_seconds', 10))));
        if ($deadline->lessThanOrEqualTo(CarbonImmutable::parse($now))) {
            return null;
        }
        $capital = isset($settings['capital']) ? $settings['capital'] * 100 : (int) config('notifications.email.capital_minor', 5000000);
        if ($capital < 10000 || $capital > 1000000000) {
            return null;
        }
        $match = $candidate['next_match'];

        return ['id' => hash('sha256', implode(':', [$candidate['competition'], $candidate['season_id'], $candidate['strategy_id'], $candidate['version']])),
            'deadline' => $deadline->toIso8601String(), 'details' => [
                'Team' => $candidate['team'], 'League' => $candidate['competition'], 'Season' => $candidate['season_id'],
                'Strategy' => $candidate['strategy_id'].' / '.$candidate['version'],
                'Qualification' => 'First win on matchday '.$candidate['trigger_day'],
                'Fixture' => $match['home_team'].' vs '.$match['away_team'], 'Selection' => $candidate['team'].' to win',
                'Matchday' => $match['matchday'], 'Attempts maximum' => $settings[$prefix.'_attempts'] ?? 4, 'Scheduled WAT' => $start->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s'),
                'Reference capital' => OpportunityNotifications::money($capital).' — total run budget, not a single stake',
                'Detected UTC' => $now, 'Action' => 'Check current bookmaker odds, balance and market cutoff before placing manually.',
                'Stake' => 'Not calculated: retrospective payout-carry sizing uses future odds and is not a live staking instruction.',
                'Timing' => 'Scheduled kickoff; bookmaker cutoff may be earlier. Notification arrival time is not guaranteed.',
            ]];
    }
}
