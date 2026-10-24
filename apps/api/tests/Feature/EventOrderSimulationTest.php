<?php

namespace Tests\Feature;

use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\RunReplay;
use App\Domain\Strategies\SimulationSeasonLinks;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class EventOrderSimulationTest extends TestCase
{
    private function season(array $results): array
    {
        $matches = [];
        foreach ($results as $i => $result) {
            $matches[] = ['provider_match_id' => 'synthetic:'.$i, 'matchday' => $i + 1,
                'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Synthetic', 'away_team' => 'Example',
                'scheduled_start_at' => CarbonImmutable::parse('2026-10-24T10:00:00Z')->addMinutes(4 * $i)->toIso8601String(),
                'status' => $result === null ? 'scheduled_or_unconfirmed' : 'settled', 'result' => $result,
                'result_observed_at' => '2026-10-24T11:00:00+00:00'];
        }

        return ['season_id' => '200', 'matches' => $matches];
    }

    private function evaluate(array $season, bool $eventOrder): array
    {
        return (new ChampionMonitor)->evaluate('champion-next-win', $season,
            ['season_id' => '100', 'champion' => ['team_uid' => '1', 'team_name' => 'Synthetic', 'confirmed_at' => '2026-10-24T09:00:00+00:00']], null,
            [['from_season_id' => '100', 'to_season_id' => '200', 'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'observed_rollover']],
            '2026-10-24T11:30:00+00:00', $eventOrder);
    }

    public function test_delayed_collection_replays_a_completed_first_attempt_win(): void
    {
        $season = $this->season(['D', 'A', 'D', 'H', 'H']);
        $row = $this->evaluate($season, true);
        $this->assertSame('observed_win', $row['status']);
        $this->assertTrue($row['recovered']);
        $this->assertSame(ChampionMonitor::SIMULATION_VERSION, $row['version']);
        $this->assertSame('late_evidence', $this->evaluate($season, false)['status']);
        $capture = ['_id' => 'recovered', 'observed_at' => '2026-10-24T11:00:00+00:00', 'matches' => $season['matches'],
            'odds_snapshots' => [['provider_match_id' => 'synthetic:4', 'captured_at' => '2026-10-24T11:00:00+00:00',
                'pre_match_verified' => false, 'prices' => ['H' => '1.5']]]];
        $view = app(RunReplay::class)->calculate($row, [$capture], 700000, '2026-10-24T11:30:00+00:00', true);
        $this->assertSame('win', $view['status']);
        $this->assertSame(20000, $view['total_staked_minor']);
        $this->assertSame(30000, $view['gross_payout_minor']);
        $this->assertSame(10000, $view['net_minor']);
        $this->assertNull($view['attempts'][0]['decided_at']);
        $this->assertFalse($view['attempts'][0]['price_timing_verified']);
        $this->assertNotEmpty($view['data_quality_notes']);
    }

    public function test_delayed_results_do_not_skip_to_a_later_win_or_extend_the_window(): void
    {
        $row = $this->evaluate($this->season(['D', 'A', 'D', 'H', 'D', 'A', 'D', 'A', 'H']), true);
        $this->assertSame('four_attempts_without_win', $row['status']);
        $this->assertSame(4, $row['attempt']);
        $missing = $this->evaluate($this->season(['D', null, 'D', 'H', 'H']), true);
        $this->assertSame('awaiting_first_win', $missing['status']);
    }

    public function test_open_window_remains_pending_and_missing_odds_are_not_invented(): void
    {
        $season = $this->season(['D', 'A', 'D', 'H', null]);
        $row = $this->evaluate($season, true);
        $this->assertSame('awaiting_result', $row['status']);
        $view = app(RunReplay::class)->calculate($row, [['_id' => 'no-price', 'observed_at' => '2026-10-24T11:00:00+00:00',
            'matches' => $season['matches'], 'odds_snapshots' => []]], 700000, '2026-10-24T11:30:00+00:00', true);
        $this->assertSame('awaiting_recorded_odds', $view['status']);
        $this->assertNull($view['net_minor']);
        $blocked = app(RunReplay::class)->calculate([...$row, 'blocked_matchdays' => [5]], [], 700000, '2026-10-24T11:30:00+00:00', true);
        $this->assertSame('market_blocked', $blocked['status']);
        $this->assertSame([], $blocked['attempts']);
    }

    public function test_catalog_order_uses_provider_times_not_numeric_id_gaps_and_preserves_first_link(): void
    {
        $plan = new SimulationSeasonLinks;
        $links = $plan->merge([], [['season_id' => '900', 'start_uts' => 100], ['season_id' => '200', 'start_uts' => 200]], '2026-10-24T11:00:00Z');
        $this->assertSame('900', $links[0]['from_season_id']);
        $this->assertSame('provider_catalog_adjacency', $links[0]['continuity_status']);
        $this->assertSame($links, $plan->merge($links, [], '2026-10-25T11:00:00Z'));
        $this->assertSame([], $plan->merge([], [['season_id' => '100', 'start_uts' => 100], ['season_id' => '200', 'start_uts' => 100]], '2026-10-24T11:00:00Z'));
    }
}
