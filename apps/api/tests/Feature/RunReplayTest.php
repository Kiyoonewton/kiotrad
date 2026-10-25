<?php

namespace Tests\Feature;

use App\Domain\Strategies\ReplayCapture;
use App\Domain\Strategies\RunReplay;
use Tests\TestCase;

class RunReplayTest extends TestCase
{
    private function row(): array
    {
        return ['strategy_id' => 'champion-next-win', 'trigger_day' => 4, 'team_uid' => '1',
            'evidence_ready_at' => '2026-10-24T10:00:00+00:00', 'status' => 'observed_win'];
    }

    private function capture(int $day, bool $settled, string $odds = '1.5', string $result = 'H'): array
    {
        $minute = ($day - 5) * 4;
        $time = sprintf('2026-10-24T10:%02d:00+00:00', $minute + ($settled ? 3 : 0));
        $match = ['provider_match_id' => 'synthetic:'.$day, 'matchday' => $day,
            'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Synthetic', 'away_team' => 'Example',
            'status' => $settled ? 'settled' : 'scheduled_or_unconfirmed', 'result' => $settled ? $result : null,
            'scheduled_start_at' => sprintf('2026-10-24T10:%02d:00+00:00', $minute + 1)];

        return ['_id' => $day.':'.($settled ? 'result' : 'price'), 'observed_at' => $time, 'matches' => [$match],
            'odds_snapshots' => $settled ? [] : [['provider_match_id' => $match['provider_match_id'],
                'captured_at' => $time, 'pre_match_verified' => true, 'prices' => ['H' => $odds]]]];
    }

    public function test_first_attempt_win_includes_stake_gross_payout_and_net_without_any_portfolio(): void
    {
        $view = app(RunReplay::class)->calculate($this->row(), [$this->capture(5, false), $this->capture(5, true)], 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame('win', $view['status']);
        $this->assertSame(20000, $view['total_staked_minor']);
        $this->assertSame(30000, $view['gross_payout_minor']);
        $this->assertSame(10000, $view['net_minor']);
        $this->assertCount(1, $view['attempts']);
    }

    public function test_compact_captures_preserve_result_only_evidence_and_settle_recovery(): void
    {
        $captures = [$this->capture(5, false), $this->capture(5, true, '1.5', 'D'),
            $this->capture(6, false, '2.0'), $this->capture(6, true, '2.0')];
        $captures = array_map(fn ($capture) => (new ReplayCapture)->forTeam($capture, '1'), $captures);
        $this->assertSame([], $captures[1]['odds_snapshots']);
        $pending = app(RunReplay::class)->calculate($this->row(), [$captures[0]], 700000, '2026-10-24T11:00:00+00:00', true);
        $this->assertSame('awaiting_result', $pending['status']);
        $view = app(RunReplay::class)->calculate($this->row(), $captures, 700000, '2026-10-24T11:00:00+00:00', true);
        $this->assertSame('win', $view['status']);
        $this->assertSame(60000, $view['total_staked_minor']);
        $this->assertSame(80000, $view['gross_payout_minor']);
        $this->assertSame(20000, $view['net_minor']);
        $this->assertNull((new ReplayCapture)->forTeam($captures[0], 'unrelated'));
    }

    public function test_recovery_recovers_actual_prior_loss_and_two_attempts_profit(): void
    {
        $captures = [$this->capture(5, false), $this->capture(5, true, '1.5', 'D'),
            $this->capture(6, false, '2.0'), $this->capture(6, true, '2.0')];
        $view = app(RunReplay::class)->calculate($this->row(), $captures, 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame(40000, $view['attempts'][1]['stake_minor']);
        $this->assertSame(60000, $view['total_staked_minor']);
        $this->assertSame(80000, $view['gross_payout_minor']);
        $this->assertSame(20000, $view['net_minor']);
    }

    public function test_unverified_prices_do_not_create_fictional_profit_from_an_observed_win(): void
    {
        $price = $this->capture(5, false);
        $price['odds_snapshots'][0]['pre_match_verified'] = false;
        $view = app(RunReplay::class)->calculate($this->row(), [$price, $this->capture(5, true)], 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame('awaiting_verified_odds', $view['status']);
        $this->assertNull($view['net_minor']);
        $this->assertSame([], $view['attempts']);
    }

    public function test_delayed_previous_result_cannot_be_used_to_enter_a_match_already_started(): void
    {
        $late = $this->capture(5, true, '1.5', 'D');
        $late['observed_at'] = '2026-10-24T10:06:00+00:00';
        $view = app(RunReplay::class)->calculate($this->row(), [$this->capture(5, false), $late,
            $this->capture(6, false, '2.0'), $this->capture(6, true, '2.0')], 700000, '2026-10-24T11:00:00+00:00');
        $this->assertCount(1, $view['attempts']);
        $this->assertSame('awaiting_verified_odds', $view['status']);
        $this->assertSame(-20000, $view['realized_net_minor']);
    }

    public function test_capital_limit_and_unreceived_result_are_not_counted_as_wins(): void
    {
        $captures = [$this->capture(5, false), $this->capture(5, true)];
        $replay = app(RunReplay::class);
        $small = $replay->calculate($this->row(), $captures, 10000, '2026-10-24T11:00:00+00:00');
        $this->assertSame('capital_limit', $small['status']);
        $this->assertSame(0, $small['total_staked_minor']);
        $pending = $replay->calculate($this->row(), $captures, 700000, '2026-10-24T10:02:00+00:00');
        $this->assertSame('awaiting_result', $pending['status']);
        $this->assertNull($pending['net_minor']);
        $this->assertSame(0, $pending['gross_payout_minor']);
    }

    public function test_four_losses_include_the_entire_cost_and_do_not_attempt_a_fifth_bet(): void
    {
        $captures = [];
        foreach (range(5, 8) as $day) {
            $captures[] = $this->capture($day, false, '2.0');
            $captures[] = $this->capture($day, true, '2.0', 'D');
        }
        $view = app(RunReplay::class)->calculate($this->row(), $captures, 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame('four_losses', $view['status']);
        $this->assertCount(4, $view['attempts']);
        $this->assertSame(-260000, $view['net_minor']);
    }

    public function test_next_season_starts_at_day_one_and_unqualified_rows_are_not_simulated(): void
    {
        $captures = [$this->capture(5, false), $this->capture(5, true)];
        foreach ($captures as &$capture) {
            $capture['matches'][0]['matchday'] = 1;
        }
        unset($capture);
        $row = [...$this->row(), 'strategy_id' => 'champion-next-season'];
        $replay = app(RunReplay::class);
        $view = $replay->calculate($row, $captures, 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame(1, $view['attempts'][0]['matchday']);
        $this->assertSame(10000, $view['net_minor']);
        $this->assertNull($replay->calculate([...$row, 'trigger_day' => 3, 'status' => 'not_qualified'], $captures, 700000, '2026-10-24T11:00:00+00:00'));
    }

    public function test_same_time_conflicting_prices_are_not_used_to_choose_a_profitable_quote(): void
    {
        $first = $this->capture(5, false, '1.5');
        $conflict = $this->capture(5, false, '2.0');
        $conflict['observed_at'] = '2026-10-24T10:00:01+00:00';
        $view = app(RunReplay::class)->calculate($this->row(), [$first, $conflict, $this->capture(5, true)], 700000, '2026-10-24T11:00:00+00:00');
        $this->assertSame('awaiting_verified_odds', $view['status']);
        $this->assertSame([], $view['attempts']);
        $this->assertNull($view['net_minor']);
    }
}
