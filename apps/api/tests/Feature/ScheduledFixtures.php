<?php

namespace Tests\Feature;

trait ScheduledFixtures
{
    private function fullSeason(string $season, int $firstKickoff, bool $settled = false): array
    {
        $teams = range(10, 25);
        $matches = [];
        for ($round = 0; $round < 15; $round++) {
            for ($i = 0; $i < 8; $i++) {
                $home = $teams[$i];
                $away = $teams[15 - $i];
                foreach ([0, 15] as $half) {
                    $day = $round + $half + 1;
                    $h = $half ? $away : $home;
                    $a = $half ? $home : $away;
                    $matches[] = [
                        '_id' => (int) $season * 1000 + ($day - 1) * 8 + $i,
                        '_seasonid' => (int) $season, '_utid' => 31867, 'round' => $day,
                        'time' => ['uts' => $firstKickoff + ($day - 1) * 220],
                        'teams' => ['home' => ['uid' => $h, 'name' => 'Team '.$h], 'away' => ['uid' => $a, 'name' => 'Team '.$a]],
                        'periods' => $settled ? ['ft' => ['home' => 1, 'away' => 0]] : [],
                    ];
                }
            }
            $last = array_pop($teams);
            array_splice($teams, 1, 0, [$last]);
        }

        return ['_id' => $season, '_utid' => 31867, 'matches' => $matches];
    }

    private function roundOdds(array $season, int $round): array
    {
        $events = [];
        foreach ($season['matches'] as $match) {
            if ($match['round'] !== $round) {
                continue;
            }
            $events[] = [
                'id' => 'vf:match:'.$match['_id'], 'tournament_id' => 'vf:tournament:31867',
                'teams' => ['home' => ['id' => 'sr:competitor:'.$match['teams']['home']['uid']], 'away' => ['id' => 'sr:competitor:'.$match['teams']['away']['uid']]],
                'market' => [['id' => 1, 'status' => 1, 'outcome' => [
                    ['id' => '1', 'odds' => '2.50', 'active' => 1, 'result' => -1],
                    ['id' => '2', 'odds' => '3.00', 'active' => 1, 'result' => -1],
                    ['id' => '3', 'odds' => '2.80', 'active' => 1, 'result' => -1],
                ]]],
            ];
        }

        return ['doc' => [['data' => ['odds' => $events]]]];
    }

    private function officialTable(string $season): array
    {
        $rows = [];
        foreach (range(25, 10) as $index => $uid) {
            $rows[] = ['pos' => $index + 1, 'total' => 30, 'winTotal' => 15, 'drawTotal' => 0,
                'lossTotal' => 15, 'pointsTotal' => 45, 'goalsForTotal' => 15,
                'goalsAgainstTotal' => 15, 'goalDiffTotal' => 0,
                'team' => ['uid' => $uid, 'name' => $uid === 25 ? 'Zulu' : 'Team '.$uid]];
        }

        return ['doc' => [['data' => ['_id' => $season, '_utid' => 31867, 'tables' => [[
            '_id' => '9001', 'seasonid' => $season, 'presentationid' => 0,
            'tournament' => ['_utid' => 31867, 'seasonid' => '999'],
            'rules' => ['name' => 'Synthetic provider order resolves remaining ties.'], 'tablerows' => $rows,
        ]]]]]];
    }

    private function discoveryDocument(string $current, int $start, ?string $next = null): array
    {
        $seasons = [['_id' => $current, '_utid' => 31867, 'current' => true,
            'start' => ['uts' => $start], 'end' => ['uts' => $start + 6720]]];
        if ($next !== null) {
            $seasons[] = ['_id' => $next, '_utid' => 31867, 'current' => false, 'start' => ['uts' => $start + 6720]];
        }

        return ['doc' => [['data' => ['seasons' => $seasons]]]];
    }
}
