<?php

namespace Tests\Feature;

use App\Infrastructure\Research\EstimatedRunCandidates;
use App\Infrastructure\Research\EstimatedSeasonEvidence;
use RuntimeException;
use Tests\TestCase;

class EstimatedSeasonEvidenceTest extends TestCase
{
    use EstimatedHistoryFixtures;

    public function test_estimates_preserve_observations_prices_and_unique_full_season_points_leader(): void
    {
        $row = $this->timingRow('100', '2026-10-17T10:00:00+00:00');
        $capture = $this->seasonCapture('100');
        $before = $capture;
        $season = (new EstimatedSeasonEvidence)->fold($row, [$capture], '2026-11-01T00:00:00+00:00');
        $this->assertSame($before, $capture);
        $this->assertTrue($season['complete']);
        $this->assertSame('1', $season['champion']['team_uid']);
        $this->assertSame('unique_points_leader_from_240_results', $season['champion']['basis']);
        $match = array_values($season['matches'])[0];
        $this->assertSame('2026-10-17T10:00:00+00:00', $match['scheduled_start_at']);
        $this->assertSame($capture['observed_at'], $season['confirmations'][$match['provider_match_id']]);
        $this->assertSame(20000, $season['prices'][$match['provider_match_id']]['H']['odds_scaled']);
        $this->assertFalse($season['prices'][$match['provider_match_id']]['H']['price_timing_verified']);
    }

    public function test_partial_and_tied_seasons_do_not_receive_a_champion(): void
    {
        $capture = $this->seasonCapture('100');
        array_pop($capture['matches']);
        $engine = new EstimatedSeasonEvidence;
        $row = $this->timingRow('100', '2026-10-17T10:00:00+00:00');
        $partial = $engine->fold($row, [$capture], '2026-11-01T00:00:00+00:00');
        $this->assertNull($partial['champion']);
        $this->assertSame('incomplete_season', $partial['champion_issue']);
        $capture = $this->seasonCapture('100');
        foreach ($capture['matches'] as &$match) {
            $match['result'] = 'D';
        }
        unset($match);
        $tied = $engine->fold($row, [$capture], '2026-11-01T00:00:00+00:00');
        $this->assertTrue($tied['complete']);
        $this->assertNull($tied['champion']);
        $this->assertSame('tied_points_champion_unresolved', $tied['champion_issue']);
    }

    public function test_conflicting_results_block_champions_and_now_recorded_dates_require_a_new_audit(): void
    {
        $capture = $this->seasonCapture('100');
        $other = $capture;
        $other['matches'][0]['result'] = 'D';
        $row = $this->timingRow('100', '2026-10-17T10:00:00+00:00');
        $season = (new EstimatedSeasonEvidence)->fold($row, [$capture, $other], '2026-11-01T00:00:00+00:00');
        $this->assertNull($season['champion']);
        $this->assertNotEmpty($season['conflicts']);
        $capture['matches'][0]['scheduled_start_at'] = '2026-10-17T10:00:00+00:00';
        $this->expectException(RuntimeException::class);
        (new EstimatedSeasonEvidence)->fold($row, [$capture], '2026-11-01T00:00:00+00:00');
    }

    public function test_both_strategies_use_the_correct_first_win_and_only_earlier_price_minima(): void
    {
        $seasons = [];
        foreach (['100' => 1, '200' => 5, '300' => 1] as $id => $win) {
            $id = (string) $id;
            $start = ['100' => '10:00:00', '200' => '11:52:00', '300' => '13:44:00'][$id];
            $seasons[] = (new EstimatedSeasonEvidence)->fold($this->timingRow($id, '2026-10-17T'.$start.'+00:00'),
                [$this->seasonCapture($id, $win)], '2026-11-01T00:00:00+00:00');
        }
        $built = (new EstimatedRunCandidates)->build($seasons, []);
        $rows = array_column($built['candidates'], null, 'id');
        $nextWin = $rows['virtual-euro:200:champion-next-win'];
        $nextSeason = $rows['virtual-euro:300:champion-next-season'];
        $this->assertSame(5, $nextWin['trigger_day']);
        $this->assertSame(6, $nextWin['fixtures'][0]['matchday']);
        $this->assertSame(5, $nextSeason['trigger_day']);
        $this->assertSame(1, $nextSeason['fixtures'][0]['matchday']);
        $this->assertSame('100', $nextWin['fixtures'][0]['historical_minimum']['season_id']);
        $this->assertTrue($nextWin['estimated_timing_dependency']);
        $seasons[0]['champion'] = null;
        $blocked = (new EstimatedRunCandidates)->build($seasons, []);
        $issues = array_column($blocked['excluded'], 'reason', 'id');
        $this->assertSame('previous_champion_unverified', $issues['virtual-euro:200:champion-next-win']);
        $this->assertSame('older_champion_unverified', $issues['virtual-euro:300:champion-next-season']);
    }
}
