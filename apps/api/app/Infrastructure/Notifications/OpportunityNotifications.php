<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Paper\WinQuote;
use App\Domain\Strategies\ChampionMonitor;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use DomainException;

final class OpportunityNotifications
{
    public function __construct(private ChampionMonitoring $monitoring, private PaperMarket $market, private PaperStore $paper, private WinQuote $quotes) {}

    public function scan(NotificationOutbox $outbox): void
    {
        $state = $this->paper->read();
        foreach ($this->monitoring->all(false) as $candidate) {
            if (($candidate['status'] ?? '') !== 'watching_fixture' || ($candidate['attempt'] ?? 0) !== 1
                || empty($candidate['team_uid']) || empty($candidate['next_match'])) {
                continue;
            }
            $now = now('UTC')->toIso8601String();
            $match = $candidate['next_match'];
            if (($match['scheduled_start_at'] ?? '') <= $now) {
                continue;
            }
            $id = implode(':', [$candidate['competition'], $candidate['season_id'], $candidate['strategy_id'], $candidate['version']]);
            $request = ['competition' => $candidate['competition'], 'season_id' => $candidate['season_id'],
                'team_uid' => $candidate['team_uid'], 'day' => $match['matchday']];
            $key = implode(':', array_values($request));
            $fact = $this->market->facts([$request], $now)[$key] ?? [];
            $details = ['League' => $candidate['competition'], 'Season' => $candidate['season_id'],
                'Strategy' => $candidate['strategy_id'].' / '.$candidate['version'],
                'Qualification' => 'Champion first win on matchday '.$candidate['trigger_day'].' ('.ChampionMonitor::MIN_FIRST_WIN_DAY.' or later).',
                'Team' => $candidate['team'], 'Fixture' => $match['home_team'].' vs '.$match['away_team'],
                'Selection' => $candidate['team'].' to win', 'Matchday' => $match['matchday'],
                'Scheduled Lagos' => CarbonImmutable::parse($match['scheduled_start_at'])->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s T'),
                'Paper entry' => $state === null ? 'Not configured — stake pending' : 'Proposed only; not a recorded stake',
                'Previous paper losses' => '₦0.00 — first attempt', 'Attempt' => '1 / 4', 'Target net profit' => '₦100.00'];
            if ($state !== null) {
                $details['Available paper capital'] = self::money($state['cash_minor']);
                $details['Shared bankroll'] = $state['active_run'] === null ? 'No active run' : 'Occupied; overlap rules may skip this opportunity';
            }
            $quote = null;
            try {
                if ($state !== null && ! ($fact['collection_interrupted'] ?? true) && isset($fact['fixture'])) {
                    $quote = $this->quotes->make($fact['fixture'], $fact['price'] ?? null, $candidate['team_uid'], 0, 1, $state['cash_minor'], $now);
                }
            } catch (DomainException $exception) {
                $details['Quote status'] = $exception->getMessage();
            }
            if ($quote) {
                $details += ['Odds' => $quote['odds'], 'Proposed stake' => self::money($quote['stake_minor']),
                    'Potential gross payout' => self::money($quote['potential_payout_minor'])];
            } else {
                $details['Proposed stake'] = 'Pending — verified price and available capital required';
            }
            $expiry = $match['scheduled_start_at'];
            if ($quote) {
                $expiry = min($expiry, CarbonImmutable::parse($quote['price_observed_at'])->addSeconds(300)->toIso8601String());
            }
            $outbox->enqueue('qualified:'.$id, 'qualified', $details, $expiry);
            if ($quote) {
                $outbox->enqueue('quote:'.$id, 'quote', $details, $expiry);
            }
        }
    }

    public static function money(int $minor): string
    {
        return '₦'.number_format($minor / 100, 2);
    }
}
