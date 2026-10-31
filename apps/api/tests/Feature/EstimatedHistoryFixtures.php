<?php

namespace Tests\Feature;

trait EstimatedHistoryFixtures
{
    private function timingRow(string $season, string $start, string $status = 'estimated_unbounded'): array
    {
        return ['competition' => 'virtual-euro', 'season_id' => $season, 'status' => $status,
            'estimated_day1_utc' => $start, 'recorded_day1_utc' => $status === 'recorded' ? $start : null,
            'proposals' => [['source_sha256' => str_repeat('a', 64)]]];
    }

    private function seasonCapture(string $season, int $firstWin = 1): array
    {
        $rotation = range(1, 16);
        $matches = $prices = [];
        for ($round = 1; $round <= 15; $round++) {
            for ($i = 0; $i < 8; $i++) {
                foreach ([0, 15] as $offset) {
                    $home = (string) $rotation[$offset === 0 ? $i : 15 - $i];
                    $away = (string) $rotation[$offset === 0 ? 15 - $i : $i];
                    $day = $round + $offset;
                    $code = $home === '1' ? 'H' : 'A';
                    $result = ($home === '1' || $away === '1') ? ($day < $firstWin ? ($code === 'H' ? 'A' : 'H') : $code) : 'D';
                    $id = 'vf:match:'.$season.'-'.$day.'-'.$i;
                    $matches[] = ['provider_match_id' => $id, 'season_id' => $season, 'matchday' => $day,
                        'home_team_uid' => $home, 'away_team_uid' => $away, 'home_team' => 'Team '.$home, 'away_team' => 'Team '.$away,
                        'scheduled_start_at' => null, 'status' => 'settled', 'result' => $result];
                    $prices[] = ['provider_match_id' => $id, 'prices' => ['H' => '2.00', 'D' => '3.00', 'A' => '2.00'],
                        'captured_at' => '2026-10-31T00:00:00+00:00', 'pre_match_verified' => false,
                        'provenance' => ['origin' => 'synthetic_test_fixture']];
                }
            }
            $last = array_pop($rotation);
            array_splice($rotation, 1, 0, [$last]);
        }

        return ['_id' => 'capture-'.$season, 'observed_at' => '2026-10-31T00:00:00+00:00', 'matches' => $matches, 'odds_snapshots' => $prices];
    }

    private function scenarioSettings(): array
    {
        return ['capital' => 50000, 'capital_mode' => 'compound', 'profit_stop' => 0, 'qualification' => 5,
            'attempts' => 4, 'allowance' => 1, 'strategy' => 'all', 'league' => 'all'];
    }
}
