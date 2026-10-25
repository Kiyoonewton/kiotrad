<?php

namespace App\Domain\Strategies;

use App\Domain\Paper\WinQuote;
use DomainException;

final class RunReplay
{
    public const EVENT_VERSION = 'event-order-win-recovery-1.0.1';

    public function __construct(private WinQuote $quotes, private HistoricalQuote $historical) {}

    public function calculate(array $row, array $captures, int $capital, string $asOf, bool $eventOrder = false): ?array
    {
        if (($row['trigger_day'] ?? 0) < 4 || empty($row['team_uid']) || empty($row['evidence_ready_at'])
            || ! in_array($row['status'], ['watching_fixture', 'awaiting_result', 'observed_win', 'four_attempts_without_win', 'window_ended', 'missing_fixture'], true)) {
            return null;
        }
        $captures = array_values(array_filter($captures, fn ($c) => $c['observed_at'] <= $asOf));
        usort($captures, fn ($a, $b) => strcmp($a['observed_at'], $b['observed_at']));
        $latest = [];
        $seen = [];
        foreach ($captures as $capture) {
            foreach ($capture['matches'] as $match) {
                $latest[$match['provider_match_id']] = $match;
                if ($match['status'] === 'settled' && in_array($match['result'] ?? null, ['H', 'D', 'A'], true)) {
                    $seen[$match['provider_match_id'].':'.$match['result']] ??= $capture['observed_at'];
                }
            }
        }
        $view = ['mode' => $eventOrder ? 'event_order_simulation' : 'independent_run_replay',
            'policy_version' => $eventOrder ? self::EVENT_VERSION : 'as-observed-win-recovery-1.0.0',
            'data_quality_notes' => $row['data_quality_notes'] ?? [],
            'starting_capital_minor' => $capital, 'total_staked_minor' => 0, 'gross_payout_minor' => 0,
            'realized_net_minor' => 0, 'net_minor' => null, 'status' => 'awaiting_fixture', 'attempts' => []];
        $ready = $row['evidence_ready_at'];
        $first = $row['strategy_id'] === 'champion-next-win' ? $row['trigger_day'] + 1 : 1;
        $losses = 0;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $day = $first + $attempt - 1;
            if ($day > 30) {
                return [...$view, 'status' => 'season_end', 'net_minor' => $view['realized_net_minor']];
            }
            if ($eventOrder && in_array($day, $row['blocked_matchdays'] ?? [], true)) {
                return [...$view, 'status' => 'market_blocked', 'unpriced_matchday' => $day];
            }
            $matches = array_values(array_filter($latest, fn ($m) => $m['matchday'] === $day
                && in_array($row['team_uid'], [$m['home_team_uid'], $m['away_team_uid']], true)));
            if (count($matches) !== 1) {
                return $view;
            }
            $fixture = $matches[0];
            if (empty($fixture['scheduled_start_at'])) {
                return $view;
            }
            $quote = $eventOrder
                ? $this->historical->make($captures, $fixture, $row['team_uid'], $losses, $attempt, $capital - $losses, $asOf)
                : $this->quote($captures, $fixture['provider_match_id'], $row['team_uid'], $ready, $losses, $attempt, $capital - $losses, $asOf);
            if (isset($quote['reason'])) {
                return [...$view, 'status' => $quote['reason'], 'unpriced_matchday' => $day];
            }
            $entry = [...$quote, 'attempt' => $attempt, 'matchday' => $day,
                'provider_match_id' => $fixture['provider_match_id'], 'home_team' => $fixture['home_team'], 'away_team' => $fixture['away_team'],
                'status' => 'pending', 'payout_minor' => null, 'result' => null];
            $view['total_staked_minor'] += $quote['stake_minor'];
            if ($eventOrder && ! $quote['price_timing_verified']) {
                $view['data_quality_notes'] = array_values(array_unique([...$view['data_quality_notes'],
                    'Historical recorded odds used; pre-match availability was not verified.']));
            }
            if ($fixture['status'] !== 'settled' || ! in_array($fixture['result'] ?? null, ['H', 'D', 'A'], true)
                || ($seen[$fixture['provider_match_id'].':'.($fixture['result'] ?? '')] ?? '') < $quote['scheduled_start_at']
                || $fixture['scheduled_start_at'] !== $quote['scheduled_start_at']
                || $fixture['home_team_uid'] !== $quote['home_team_uid'] || $fixture['away_team_uid'] !== $quote['away_team_uid']) {
                $view['attempts'][] = $entry;

                return [...$view, 'status' => 'awaiting_result'];
            }
            $payout = $fixture['result'] === $quote['outcome'] ? $quote['potential_payout_minor'] : 0;
            $observed = $seen[$fixture['provider_match_id'].':'.$fixture['result']];
            $ready = $eventOrder ? $ready : max($quote['decided_at'], $observed);
            $view['attempts'][] = [...$entry, 'status' => 'settled', 'payout_minor' => $payout, 'result' => $fixture['result'], 'result_observed_at' => $observed];
            $view['gross_payout_minor'] += $payout;
            $view['realized_net_minor'] += $payout - $quote['stake_minor'];
            if ($payout > 0) {
                return [...$view, 'status' => 'win', 'net_minor' => $view['realized_net_minor']];
            }
            $losses += $quote['stake_minor'];
        }

        return [...$view, 'status' => 'four_losses', 'net_minor' => $view['realized_net_minor']];
    }

    private function quote(array $captures, string $matchId, string $team, string $ready, int $losses, int $attempt, int $cash, string $asOf): array
    {
        $versions = [];
        $conflicts = [];
        foreach ($captures as $capture) {
            foreach ($capture['odds_snapshots'] ?? [] as $price) {
                $key = $price['provider_match_id'].':'.$price['captured_at'];
                $prices = $price['prices'];
                ksort($prices);
                $value = json_encode([$prices, $price['pre_match_verified']], JSON_THROW_ON_ERROR);
                if (isset($versions[$key]) && $versions[$key] !== $value) {
                    $conflicts[$key] = true;
                }
                $versions[$key] = $value;
            }
        }
        foreach ($captures as $capture) {
            $matches = array_values(array_filter($capture['matches'], fn ($m) => $m['provider_match_id'] === $matchId));
            if (count($matches) !== 1) {
                continue;
            }
            foreach ($capture['odds_snapshots'] ?? [] as $price) {
                if ($price['provider_match_id'] !== $matchId || isset($conflicts[$price['provider_match_id'].':'.$price['captured_at']])) {
                    continue;
                }
                $decision = max($ready, $capture['observed_at'], $price['captured_at']);
                if ($decision > $asOf) {
                    continue;
                }
                try {
                    $quote = $this->quotes->make($matches[0], [...$price, 'capture_id' => $capture['_id'], 'stored_at' => $capture['observed_at']],
                        $team, $losses, $attempt, $cash, $decision);

                    return [...$quote, 'decided_at' => $decision, 'scheduled_start_at' => $matches[0]['scheduled_start_at'],
                        'home_team_uid' => $matches[0]['home_team_uid'], 'away_team_uid' => $matches[0]['away_team_uid']];
                } catch (DomainException $exception) {
                    if ($exception->getMessage() === 'capital_limit') {
                        return ['reason' => 'capital_limit'];
                    }
                }
            }
        }

        return ['reason' => 'awaiting_verified_odds'];
    }
}
