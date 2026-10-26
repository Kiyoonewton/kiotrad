<?php

namespace Tests\Feature;

use App\Domain\Research\RunBudgetEstimate;
use App\Domain\Research\SharedCapitalReplay;
use Tests\TestCase;

class SharedCapitalReplayTest extends TestCase
{
    private function candidate(string $id, string $entry, string $confirmed, int $price = 20000): array
    {
        return ['id' => $id, 'fixtures' => [['scheduled_start_at' => $entry,
            'historical_odds_scaled' => $price, 'actual_odds_scaled' => 20000,
            'won' => true, 'result_confirmed_at' => $confirmed]]];
    }

    public function test_simultaneous_entries_choose_highest_preview_total_without_looking_at_winners(): void
    {
        $at = '2026-10-20T10:00:00Z';
        $end = '2026-10-20T10:02:00Z';
        $a = $this->candidate('a', $at, $end, 33300);
        $b = $this->candidate('b', $at, $end, 20000);
        $estimateA = (new RunBudgetEstimate)->calculate([33300], 5000000);
        $estimateB = (new RunBudgetEstimate)->calculate([20000], 5000000);
        $this->assertGreaterThan($estimateA['plan']['total_minor'], $estimateB['plan']['total_minor']);
        $engine = new SharedCapitalReplay;
        $result = $engine->calculate([$a, $b], 5000000, $end);
        $this->assertSame('b', $result['runs'][0]['id']);
        $this->assertSame('skipped_simultaneous_entry', $result['runs'][1]['status']);
        $this->assertSame($result, $engine->calculate([$b, $a], 5000000, $end));
        $this->assertSame(7500000, $result['balance_minor']);
        $b['fixtures'][0]['won'] = false;
        $this->assertSame('b', $engine->calculate([$a, $b], 5000000, $end)['runs'][0]['id']);
    }

    public function test_confirmed_win_before_next_entry_releases_and_resizes_from_updated_balance(): void
    {
        $result = (new SharedCapitalReplay)->calculate([
            $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T10:02:00Z'),
            $this->candidate('b', '2026-10-20T10:03:00Z', '2026-10-20T10:05:00Z'),
        ], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame(['win', 'win'], array_column($result['runs'], 'status'));
        $this->assertSame(7500000, $result['runs'][1]['capital_at_entry_minor']);
        $this->assertSame(11250000, $result['balance_minor']);
        $this->assertCount(2, $result['equity']);
    }

    public function test_overlap_and_identical_confirmation_time_are_skipped_without_profit(): void
    {
        $result = (new SharedCapitalReplay)->calculate([
            $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T10:02:00Z'),
            $this->candidate('b', '2026-10-20T10:01:00Z', '2026-10-20T10:04:00Z'),
            $this->candidate('c', '2026-10-20T10:02:00Z', '2026-10-20T10:04:00Z'),
        ], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame(['win', 'skipped_overlap', 'skipped_overlap'], array_column($result['runs'], 'status'));
        $this->assertSame(7500000, $result['balance_minor']);
        $this->assertCount(1, $result['equity']);
    }

    public function test_unresolved_result_keeps_run_active_and_never_fabricates_release(): void
    {
        $result = (new SharedCapitalReplay)->calculate([
            $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T12:00:00Z'),
            $this->candidate('b', '2026-10-20T10:03:00Z', '2026-10-20T10:05:00Z'),
        ], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('a', $result['active_run_id']);
        $this->assertSame('awaiting_result', $result['runs'][0]['status']);
        $this->assertNull($result['runs'][0]['net_minor']);
        $this->assertSame([], $result['equity']);
        $this->assertSame('skipped_overlap', $result['runs'][1]['status']);
    }

    public function test_recovery_target_is_frozen_and_four_losses_reduce_shared_capital(): void
    {
        $a = ['id' => 'a', 'fixtures' => []];
        for ($i = 0; $i < 4; $i++) {
            $a['fixtures'][] = ['scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4),
                'result_confirmed_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4 + 2),
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => false];
        }
        $result = (new SharedCapitalReplay)->calculate([$a], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('four_losses', $result['runs'][0]['status']);
        $this->assertSame([96100, 288300, 672700, 1441500], array_column($result['runs'][0]['attempts'], 'stake_minor'));
        $this->assertSame(2501400, $result['balance_minor']);
    }

    public function test_equal_estimates_use_stable_id_and_timezone_offsets_compare_as_instants(): void
    {
        $result = (new SharedCapitalReplay)->calculate([
            $this->candidate('z', '2026-10-20T11:00:00+01:00', '2026-10-20T11:02:00+01:00'),
            $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T10:02:00Z'),
        ], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('a', $result['runs'][0]['id']);
        $this->assertSame('skipped_simultaneous_entry', $result['runs'][1]['status']);
    }

    public function test_missing_prices_and_prices_below_floor_do_not_create_fictitious_profit(): void
    {
        $a = $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T10:02:00Z');
        $a['fixtures'][0]['actual_odds_scaled'] = null;
        $engine = new SharedCapitalReplay;
        $result = $engine->calculate([$a], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('awaiting_price_or_market_resolution', $result['runs'][0]['status']);
        $this->assertSame(5000000, $result['reserved_allowance_minor']);
        $this->assertSame(0, $result['unreserved_capital_minor']);
        $this->assertSame(5000000, $result['balance_minor']);
        $a['fixtures'][0]['actual_odds_scaled'] = 10100;
        $result = $engine->calculate([$a], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('allowance_stop', $result['runs'][0]['status']);
        $this->assertSame(0, $result['runs'][0]['total_staked_minor']);
        $this->assertSame(5000000, $result['balance_minor']);
    }

    public function test_budget_shortage_is_a_skip_and_missing_history_keeps_fixture_evidence(): void
    {
        $candidate = $this->candidate('budget', '2026-10-20T10:00:00Z', '2026-10-20T10:02:00Z');
        $engine = new SharedCapitalReplay;
        $view = $engine->calculate([$candidate], 19999, '2026-10-20T11:00:00Z');
        $this->assertSame('skipped_budget', $view['runs'][0]['status']);
        $this->assertSame('insufficient_allowance', $view['runs'][0]['skip_reason']);
        $this->assertSame(19999, $view['balance_minor']);
        $this->assertNull($view['active_run_id']);
        $candidate['fixtures'][0]['historical_odds_scaled'] = null;
        $view = $engine->calculate([$candidate], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('insufficient_fixture_history', $view['runs'][0]['status']);
        $this->assertCount(1, $view['runs'][0]['missing_historical_fixtures']);
        $this->assertSame(5000000, $view['balance_minor']);
    }

    public function test_fifty_thousand_capital_uses_scaled_target_at_actual_one_point_three_five_odds(): void
    {
        $fixtures = [];
        foreach (range(0, 3) as $i) {
            $fixtures[] = ['scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4),
                'result_confirmed_at' => sprintf('2026-10-20T10:%02d:00Z', $i * 4 + 2),
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 13500, 'won' => true];
        }
        $view = (new SharedCapitalReplay)->calculate([['id' => 'example', 'fixtures' => $fixtures]], 5000000, '2026-10-20T11:00:00Z');
        $run = $view['runs'][0];
        $this->assertSame(96100, $run['estimate']['plan']['profit_per_attempt_minor']);
        $this->assertSame(274600, $run['total_staked_minor']);
        $this->assertSame(370710, $run['gross_payout_minor']);
        $this->assertSame(96110, $run['net_minor']);
        $this->assertSame(5096110, $view['balance_minor']);
    }

    public function test_single_allowance_after_loss_returns_to_double_only_after_recovery(): void
    {
        $base = new \DateTimeImmutable('2026-10-20T10:00:00Z');
        $candidates = [];
        foreach (range(0, 40) as $run) {
            $fixtures = [];
            foreach (range(0, 3) as $attempt) {
                $minutes = $run * 20 + $attempt * 4;
                $fixtures[] = ['scheduled_start_at' => $base->modify('+'.$minutes.' minutes')->format(DATE_ATOM),
                    'result_confirmed_at' => $base->modify('+'.($minutes + 2).' minutes')->format(DATE_ATOM),
                    'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => $run !== 0];
            }
            $candidates[] = ['id' => 'run:'.$run, 'fixtures' => $fixtures];
        }
        $view = (new SharedCapitalReplay)->calculate($candidates, 5000000, '2026-10-21T10:00:00Z');
        $this->assertSame(2, $view['runs'][0]['estimate']['allowance_multiplier']);
        $this->assertSame(1, $view['runs'][1]['estimate']['allowance_multiplier']);
        $this->assertSame(5000000, $view['runs'][1]['recovery_target_at_entry_minor']);
        $this->assertSame($view['runs'][1]['estimate']['plan']['total_minor'], $view['runs'][1]['estimate']['allowance_minor']);
        $restored = false;
        foreach (array_slice($view['runs'], 1) as $run) {
            if ($run['capital_at_entry_minor'] < 5000000) {
                $this->assertSame(1, $run['estimate']['allowance_multiplier']);
            } else {
                $this->assertSame(2, $run['estimate']['allowance_multiplier']);
                $restored = true;
            }
        }
        $this->assertTrue($restored);
        $this->assertNull($view['recovery_target_minor']);
    }

    public function test_skipped_window_outcomes_do_not_change_shared_profit(): void
    {
        $a = $this->candidate('a', '2026-10-20T10:00:00Z', '2026-10-20T10:10:00Z');
        $b = $this->candidate('b', '2026-10-20T10:01:00Z', '2026-10-20T10:03:00Z');
        $c = $this->candidate('c', '2026-10-20T10:02:00Z', '2026-10-20T10:04:00Z');
        $c['fixtures'][0]['won'] = false;
        $view = (new SharedCapitalReplay)->calculate([$a, $b, $c], 5000000, '2026-10-20T11:00:00Z');
        $this->assertSame('win', $view['runs'][1]['observed_window']['result']);
        $this->assertSame('no_win', $view['runs'][2]['observed_window']['result']);
        $this->assertArrayNotHasKey('net_minor', $view['runs'][1]);
        $this->assertSame(7500000, $view['balance_minor']);
        $this->assertCount(1, $view['equity']);
    }

    public function test_another_loss_keeps_original_recovery_threshold_and_skips_unaffordable_runs(): void
    {
        $base = new \DateTimeImmutable('2026-10-20T10:00:00Z');
        $candidates = [];
        foreach (range(0, 2) as $run) {
            $fixtures = [];
            foreach (range(0, 3) as $attempt) {
                $minutes = $run * 20 + $attempt * 4;
                $fixtures[] = ['scheduled_start_at' => $base->modify('+'.$minutes.' minutes')->format(DATE_ATOM),
                    'result_confirmed_at' => $base->modify('+'.($minutes + 2).' minutes')->format(DATE_ATOM),
                    'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => false];
            }
            $candidates[] = ['id' => 'run:'.$run, 'fixtures' => $fixtures];
        }
        $view = (new SharedCapitalReplay)->calculate($candidates, 5000000, '2026-10-21T10:00:00Z');
        $this->assertSame(5000000, $view['recovery_target_minor']);
        $this->assertSame(1, $view['allowance_multiplier']);
        $this->assertSame('skipped_budget', $view['runs'][2]['status']);
        $this->assertGreaterThanOrEqual(0, $view['balance_minor']);
        $this->assertNull($view['active_run_id']);
    }
}
