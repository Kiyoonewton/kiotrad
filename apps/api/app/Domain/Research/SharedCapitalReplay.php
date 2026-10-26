<?php

namespace App\Domain\Research;

use DateTimeImmutable;
use DomainException;

final class SharedCapitalReplay
{
    public const VERSION = 'single-active-highest-estimated-cost-1.0.1';

    public function calculate(array $candidates, int $capital, string $asOf): array
    {
        if ($capital < 1 || $capital > 1000000000) {
            throw new DomainException('invalid_capital');
        }
        // Normalize offsets before sorting or comparing instants.
        $asOf = $this->time($asOf);
        $ids = [];
        foreach ($candidates as &$candidate) {
            if (isset($ids[$candidate['id']]) || empty($candidate['fixtures']) || count($candidate['fixtures']) > 4) {
                throw new DomainException('invalid_candidate');
            }
            $ids[$candidate['id']] = true;
            foreach ($candidate['fixtures'] as &$fixture) {
                $fixture['scheduled_start_at'] = $this->time($fixture['scheduled_start_at']);
                if (isset($fixture['result_confirmed_at'])) {
                    $fixture['result_confirmed_at'] = $this->time($fixture['result_confirmed_at']);
                }
            }
            unset($fixture);
            $candidate['entry_at'] = $candidate['fixtures'][0]['scheduled_start_at'];
            $starts = array_column($candidate['fixtures'], 'scheduled_start_at');
            $sorted = $starts;
            sort($sorted);
            if ($starts !== $sorted || count(array_unique($starts)) !== count($starts)) {
                throw new DomainException('invalid_fixture_order');
            }
        }
        unset($candidate);
        usort($candidates, fn ($a, $b) => [$a['entry_at'], $a['id']] <=> [$b['entry_at'], $b['id']]);
        $groups = [];
        foreach ($candidates as $candidate) {
            if ($candidate['entry_at'] <= $asOf) {
                $groups[$candidate['entry_at']][] = $candidate;
            }
        }
        $balance = $capital;
        $active = null;
        $rows = [];
        $equity = [];
        foreach ($groups as $at => $group) {
            // Strictly before: simultaneous confirmation and entry remain a clash.
            if ($active !== null && $active['released_at'] !== null && $active['released_at'] < $at) {
                $balance += $active['net_minor'];
                $equity[] = ['at' => $active['released_at'], 'balance_minor' => $balance, 'run_id' => $active['id']];
                $active = null;
            }
            if ($active !== null) {
                foreach ($group as $candidate) {
                    $rows[] = ['id' => $candidate['id'], 'entry_at' => $at, 'status' => 'skipped_overlap', 'blocked_by' => $active['id']];
                }

                continue;
            }
            $eligible = [];
            foreach ($group as $candidate) {
                $odds = array_column($candidate['fixtures'], 'historical_odds_scaled');
                if (count($odds) !== count($candidate['fixtures']) || in_array(null, $odds, true)) {
                    $rows[] = [...$candidate, 'entry_at' => $at, 'status' => 'insufficient_fixture_history',
                        'missing_historical_fixtures' => array_values(array_filter($candidate['fixtures'],
                            fn ($fixture) => ! isset($fixture['historical_odds_scaled'])))];

                    continue;
                }
                $estimate = (new RunBudgetEstimate)->calculate($odds, $balance);
                if ($estimate['status'] !== 'estimated') {
                    $rows[] = [...$candidate, 'entry_at' => $at, 'status' => 'skipped_budget',
                        'skip_reason' => 'insufficient_allowance', 'capital_at_entry_minor' => $balance,
                        'budget_diagnostic' => $estimate];

                    continue;
                }
                $eligible[] = [...$candidate, 'estimate' => $estimate];
            }
            usort($eligible, fn ($a, $b) => ($b['estimate']['plan']['total_minor'] <=> $a['estimate']['plan']['total_minor'])
                ?: strcmp($a['id'], $b['id']));
            foreach ($eligible as $index => $candidate) {
                if ($index > 0) {
                    $rows[] = ['id' => $candidate['id'], 'entry_at' => $at, 'status' => 'skipped_simultaneous_entry',
                        'blocked_by' => $active['id'], 'estimate' => $candidate['estimate']];

                    continue;
                }
                $settled = (new SharedRunSettlement)->calculate($candidate, $candidate['estimate'], $asOf);
                $active = ['id' => $candidate['id'], 'allowance_minor' => $candidate['estimate']['allowance_minor'], ...$settled];
                $rows[] = [...$candidate, ...$settled, 'capital_at_entry_minor' => $balance,
                    'selection_reason' => 'highest_estimated_total_then_stable_run_id'];
            }
        }
        if ($active !== null && $active['released_at'] !== null && $active['released_at'] <= $asOf) {
            $balance += $active['net_minor'];
            $equity[] = ['at' => $active['released_at'], 'balance_minor' => $balance, 'run_id' => $active['id']];
            $active = null;
        }

        return ['policy_version' => self::VERSION, 'mode' => 'shared_capital_simulation', 'as_of' => $asOf,
            'timing_basis' => 'recorded_result_confirmation_conservative', 'starting_capital_minor' => $capital,
            'balance_minor' => $balance, 'reserved_allowance_minor' => $active['allowance_minor'] ?? 0,
            'unreserved_capital_minor' => $balance - ($active['allowance_minor'] ?? 0),
            'active_staked_minor' => $active['total_staked_minor'] ?? 0, 'active_run_id' => $active['id'] ?? null,
            'equity' => $equity, 'runs' => $rows];
    }

    private function time(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
