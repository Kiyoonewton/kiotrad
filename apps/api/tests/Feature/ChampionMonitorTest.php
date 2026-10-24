<?php

namespace Tests\Feature;

use App\Domain\Strategies\ChampionMonitor;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ChampionMonitorTest extends TestCase
{
    private function season(string $id, array $results, string $base = '2026-10-24T10:00:00+00:00'): array
    {
        $matches = [];
        foreach ($results as $index => $result) {
            $time = CarbonImmutable::parse($base)->addMinutes($index * 4);
            $matches[] = ['provider_match_id' => 'vf:match:'.$id.$index, 'matchday' => $index + 1,
                'home_team_uid' => '1', 'away_team_uid' => '2', 'home_team' => 'Champion', 'away_team' => 'Opponent',
                'scheduled_start_at' => $time->toIso8601String(), 'status' => $result ? 'settled' : 'scheduled_or_unconfirmed',
                'result' => $result, 'result_observed_at' => $time->addMinutes(3)->toIso8601String()];
        }

        return ['season_id' => $id, 'matches' => $matches,
            'champion' => ['team_uid' => '1', 'team_name' => 'Champion', 'confirmed_at' => '2026-10-23T10:00:00+00:00']];
    }

    private function evaluate(array $current, ?array $prior = null, array $links = []): array
    {
        $prior ??= $this->season('previous', []);
        $links = $links ?: [['from_season_id' => 'previous', 'to_season_id' => 'current',
            'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'observed_rollover']];

        return (new ChampionMonitor)->evaluate('champion-next-win', $current, $prior, null, $links, '2026-10-24T10:15:30+00:00');
    }

    public function test_fourth_day_first_win_watches_day_five_without_claiming_a_bet(): void
    {
        $row = $this->evaluate($this->season('current', ['D', 'A', 'D', 'H', null]));
        $this->assertSame('watching_fixture', $row['status']);
        $this->assertSame(4, $row['trigger_day']);
        $this->assertSame(1, $row['attempt']);
        $this->assertSame(5, $row['next_match']['matchday']);
        $this->assertFalse($row['execution_enabled']);
    }

    public function test_earlier_win_does_not_qualify(): void
    {
        $this->assertSame('not_qualified', $this->evaluate($this->season('current', ['D', 'H']))['status']);
    }

    public function test_missing_result_cannot_be_skipped_to_find_a_trigger(): void
    {
        $this->assertSame('awaiting_first_win', $this->evaluate($this->season('current', ['D', null, 'D', 'H']))['status']);
    }

    public function test_historical_champion_received_after_opening_is_blocked(): void
    {
        $prior = $this->season('previous', []);
        $prior['champion']['confirmed_at'] = '2026-10-24T10:01:00+00:00';
        $this->assertSame('late_champion_evidence', $this->evaluate($this->season('current', ['D', 'A', 'D', 'H']), $prior)['status']);
    }

    public function test_numeric_neighbours_without_observed_rollover_are_not_linked(): void
    {
        $links = [['from_season_id' => 'previous', 'to_season_id' => 'current',
            'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'unconfirmed_gap']];
        $this->assertSame('waiting_for_history', $this->evaluate($this->season('current', ['H']), null, $links)['status']);
    }

    public function test_four_non_wins_exhaust_the_window_and_do_not_chase_further(): void
    {
        $current = $this->season('current', ['D', 'A', 'D', 'H', 'D', 'A', 'D', 'A', 'H']);
        $links = [['from_season_id' => 'previous', 'to_season_id' => 'current',
            'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'observed_rollover']];
        $row = (new ChampionMonitor)->evaluate('champion-next-win', $current, $this->season('previous', []), null, $links, '2026-10-24T11:00:00+00:00');
        $this->assertSame('four_attempts_without_win', $row['status']);
        $this->assertSame(4, $row['attempt']);
    }

    public function test_trigger_result_received_after_next_kickoff_blocks_entry(): void
    {
        $current = $this->season('current', ['D', 'A', 'D', 'H', null]);
        $current['matches'][3]['result_observed_at'] = '2026-10-24T10:17:00+00:00';
        $links = [['from_season_id' => 'previous', 'to_season_id' => 'current',
            'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'observed_rollover']];
        $row = (new ChampionMonitor)->evaluate('champion-next-win', $current, $this->season('previous', []), null, $links, '2026-10-24T10:18:00+00:00');
        $this->assertSame('late_evidence', $row['status']);
    }

    public function test_next_season_follows_its_own_previous_champion(): void
    {
        $older = $this->season('older', []);
        $prior = $this->season('previous', ['D', 'A', 'D', 'H'], '2026-10-23T12:00:00+00:00');
        $prior['champion']['confirmed_at'] = '2026-10-24T09:50:00+00:00';
        $current = $this->season('current', [null]);
        $links = array_map(fn ($pair) => ['from_season_id' => $pair[0], 'to_season_id' => $pair[1],
            'observed_at' => '2026-10-24T09:59:00+00:00', 'continuity_status' => 'observed_rollover'],
            [['older', 'previous'], ['previous', 'current']]);
        $row = (new ChampionMonitor)->evaluate('champion-next-season', $current, $prior, $older, $links, '2026-10-24T09:59:30+00:00');
        $this->assertSame('watching_fixture', $row['status']);
        $this->assertSame(1, $row['next_match']['matchday']);
        $links[1]['observed_at'] = '2026-10-24T10:00:30+00:00';
        $late = (new ChampionMonitor)->evaluate('champion-next-season', $current, $prior, $older, $links, '2026-10-24T10:01:00+00:00');
        $this->assertSame('late_evidence', $late['status']);
    }
}
