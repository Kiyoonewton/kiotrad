<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Paper\PaperStore;
use Carbon\CarbonImmutable;

final class PaperNotifications
{
    public function __construct(private PaperStore $store) {}

    public function scan(NotificationOutbox $outbox, string $since): void
    {
        $state = $this->store->read();
        if ($state === null) {
            return;
        }
        $runs = $state['runs'];
        if ($state['active_run']) {
            $runs[] = $state['active_run'];
        }
        foreach ($runs as $run) {
            $details = ['League' => $run['competition'], 'Season' => $run['season_id'],
                'Strategy' => $run['strategy_id'], 'Team' => $run['team'], 'Run ID' => $run['id']];
            foreach ($run['attempts'] as $attempt) {
                if ($attempt['decided_at'] < $since) {
                    continue;
                }
                $outbox->enqueue('stake:'.$run['id'].':'.$attempt['attempt'], 'stake', $details + [
                    'Matchday' => $attempt['matchday'], 'Match ID' => $attempt['provider_match_id'],
                    'Attempt' => $attempt['attempt'].' / 4', 'Outcome' => $attempt['outcome'], 'Odds' => $attempt['odds'],
                    'Paper stake' => OpportunityNotifications::money($attempt['stake_minor']),
                    'Potential gross payout' => OpportunityNotifications::money($attempt['potential_payout_minor']),
                    'Target net profit after recovery' => OpportunityNotifications::money($attempt['target_profit_minor']),
                    'Scheduled Lagos' => CarbonImmutable::parse($attempt['scheduled_start_at'])->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s T'),
                    'Decided UTC' => $attempt['decided_at'], 'Execution' => 'Paper only — no bookmaker bet placed',
                ]);
            }
            if (($run['ended_at'] ?? '') >= $since) {
                $outbox->enqueue('settled:'.$run['id'], 'settled', $details + [
                    'Status' => $run['status'], 'Attempts' => count($run['attempts']),
                    'Total staked' => OpportunityNotifications::money(array_sum(array_column($run['attempts'], 'stake_minor'))),
                    'Gross payouts' => OpportunityNotifications::money(array_sum(array_column($run['attempts'], 'payout_minor'))),
                    'Run net' => OpportunityNotifications::money($run['net_minor']), 'Ended UTC' => $run['ended_at'],
                ]);
            }
        }
    }
}
