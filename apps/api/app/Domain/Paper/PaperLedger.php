<?php

namespace App\Domain\Paper;

use DomainException;

final class PaperLedger
{
    public const VERSION = 'paper-win-recovery-1.0.0';

    public function __construct(private WinQuote $quotes) {}

    public function open(int $capital, ?int $target, string $now): array
    {
        if ($capital < 10000 || $capital > 1000000000 || ($target !== null && $target <= $capital)) {
            throw new DomainException('Use capital between NGN 100 and NGN 10,000,000; target must exceed capital.');
        }

        return ['revision' => 0, 'currency' => 'NGN', 'policy_version' => self::VERSION, 'created_at' => $now,
            'starting_balance_minor' => $capital, 'cash_minor' => $capital, 'committed_minor' => 0,
            'target_balance_minor' => $target, 'active_run' => null, 'runs' => [], 'decisions' => [],
            'capital_history' => [['at' => $now, 'balance_minor' => $capital]]];
    }

    public function advance(array $state, array $candidates, array $facts, string $now): array
    {
        if ($state['policy_version'] !== self::VERSION) {
            throw new DomainException('Paper policy requires an explicit migration.');
        }
        if ($now < $state['created_at']) {
            throw new DomainException('Paper clock moved backwards.');
        }
        // The entire ledger is committed with one revision-checked MongoDB write.
        if ($state['active_run'] !== null) {
            $state = $this->active($state, $facts, $now);
        }
        foreach ($candidates as $candidate) {
            if ($candidate['status'] !== 'watching_fixture' || ! isset($candidate['team_uid'], $candidate['next_match'])) {
                continue;
            }
            $id = $candidate['competition'].':'.$candidate['season_id'].':'.$candidate['strategy_id'].':'.$candidate['version'];
            if (($state['active_run']['id'] ?? null) === $id || in_array($id, array_column($state['runs'], 'id'), true)) {
                continue;
            }
            if (count($state['runs']) >= 250 || count($state['decisions']) >= 2000) {
                break; // Bounded pilot journal: never silently discard audit history.
            }
            $key = implode(':', [$candidate['competition'], $candidate['season_id'], $candidate['team_uid'], $candidate['next_match']['matchday']]);
            if ($facts[$key]['collection_interrupted'] ?? false) {
                continue;
            }
            $run = ['id' => $id, 'competition' => $candidate['competition'], 'season_id' => $candidate['season_id'],
                'strategy_id' => $candidate['strategy_id'], 'strategy_version' => $candidate['version'],
                'policy_version' => self::VERSION, 'team_uid' => $candidate['team_uid'], 'team' => $candidate['team'],
                'first_day' => $candidate['next_match']['matchday'], 'started_at' => $now,
                'trigger' => array_intersect_key($candidate, array_flip(['trigger_day', 'evidence_ready_at', 'evaluated_at'])),
                'attempts' => [], 'losses_minor' => 0, 'net_minor' => 0, 'status' => 'waiting_entry'];
            if ($state['active_run'] !== null || ($candidate['attempt'] ?? 0) !== 1) {
                $run['status'] = $state['active_run'] !== null ? 'overlap_skipped' : 'missed_first_entry';
                $run['ended_at'] = $now;
                $state['runs'][] = $run;

                continue;
            }
            $state['active_run'] = $run;
            $state = $this->active($state, $facts, $now);
        }

        return $state;
    }

    private function active(array $state, array $facts, string $now): array
    {
        $run = $state['active_run'];
        $last = $run['attempts'] === [] ? null : $run['attempts'][array_key_last($run['attempts'])];
        $day = $last && $last['status'] === 'pending' ? $last['matchday'] : $run['first_day'] + count($run['attempts']);
        $key = $run['competition'].':'.$run['season_id'].':'.$run['team_uid'].':'.$day;
        $fact = $facts[$key] ?? null;
        $fixture = $fact['fixture'] ?? null;
        if ($last && $last['status'] === 'pending') {
            if (! $fixture || $fixture['provider_match_id'] !== $last['provider_match_id'] || $fixture['status'] !== 'settled') {
                return $state;
            }
            // Settlement must be from a received capture, not an unobserved future result.
            if (($fact['observed_at'] ?? '') === '' || $fact['observed_at'] > $now || $fact['observed_at'] < $last['scheduled_start_at'] || ! in_array($fixture['result'], ['H', 'D', 'A'], true)) {
                return $state;
            }
            $payout = $fixture['result'] === $last['outcome'] ? $last['potential_payout_minor'] : 0;
            $index = array_key_last($run['attempts']);
            $run['attempts'][$index] = [...$last, 'status' => 'settled', 'result' => $fixture['result'],
                'payout_minor' => $payout, 'settled_at' => $now, 'result_capture_id' => $fact['capture_id'],
                'result_observed_at' => $fact['observed_at']];
            $state['cash_minor'] += $payout;
            $state['committed_minor'] = 0;
            $run['net_minor'] += $payout - $last['stake_minor'];
            if ($payout === 0) {
                $run['losses_minor'] += $last['stake_minor'];
            }
            $run['status'] = 'waiting_entry';
            $state['capital_history'][] = ['at' => $now, 'balance_minor' => $state['cash_minor']];
            $state['active_run'] = $run;
            if ($payout > 0 || count($run['attempts']) === 4) {
                return $this->close($state, $payout > 0 ? 'win' : 'four_losses', $now);
            }

            return $state; // Next tick obtains the next fixture with fresh evidence.
        }
        if ($day > 30) {
            return $this->close($state, 'season_end', $now);
        }
        if (! $fixture || ! ($fixture['scheduled_start_at'] ?? null)) {
            return $state;
        }
        if (($run['trigger']['evidence_ready_at'] ?? $now) > $now || ! isset($fact['observed_at']) || $fact['observed_at'] > $now) {
            return $state;
        }
        if ($fact['collection_interrupted'] ?? false) {
            $state['active_run']['status'] = 'collection_interrupted';

            return $state;
        }
        try {
            $quote = $this->quotes->make($fixture, $fact['price'] ?? null, $run['team_uid'],
                $run['losses_minor'], count($run['attempts']) + 1, $state['cash_minor'], $now);
        } catch (DomainException $exception) {
            $state['active_run']['status'] = $exception->getMessage();
            if (in_array($exception->getMessage(), ['entry_closed', 'capital_limit'], true)) {
                return $this->close($state, $exception->getMessage(), $now);
            }

            return $state;
        }
        $decision = [...$quote, 'provider_match_id' => $fixture['provider_match_id'], 'matchday' => $day,
            'scheduled_start_at' => $fixture['scheduled_start_at'], 'status' => 'pending', 'decided_at' => $now,
            'run_id' => $run['id'], 'attempt' => count($run['attempts']) + 1, 'fixture_capture_id' => $fact['capture_id']];
        $state['active_run']['attempts'][] = $decision;
        $state['active_run']['status'] = 'awaiting_result';
        $state['cash_minor'] -= $quote['stake_minor'];
        $state['committed_minor'] = $quote['stake_minor'];
        $state['decisions'][] = $decision;

        return $state;
    }

    private function close(array $state, string $reason, string $now): array
    {
        $state['runs'][] = [...$state['active_run'], 'status' => $reason, 'ended_at' => $now];
        $state['active_run'] = null;

        return $state;
    }
}
