<?php

namespace App\Domain\Strategies;

final class ChampionMonitor
{
    public const VERSION = '0.2.0-observation';

    public function evaluate(string $strategy, array $current, ?array $prior, ?array $older, array $links, string $asOf): array
    {
        $row = ['strategy_id' => $strategy, 'version' => self::VERSION, 'season_id' => $current['season_id'] ?? null,
            'status' => 'waiting_for_history', 'team' => null, 'trigger_day' => null, 'attempt' => null,
            'next_match' => null, 'execution_enabled' => false, 'evaluated_at' => $asOf];
        $champion = $prior['champion'] ?? null;
        if (! $champion || ! $this->linked($prior, $current, $links, $asOf)) {
            return $row;
        }
        $row['team'] = $champion['team_name'];
        $triggerSeason = $strategy === 'champion-next-win' ? $current : $prior;
        $triggerChampion = $strategy === 'champion-next-win' ? $champion : ($older['champion'] ?? null);
        if (! $triggerChampion || ($strategy === 'champion-next-season' && ! $this->linked($older, $prior, $links, $asOf))) {
            return $row;
        }
        $opening = $this->teamMatches($triggerSeason, $triggerChampion['team_uid'])[1] ?? null;
        if (! $opening || ! $opening['scheduled_start_at'] || $triggerChampion['confirmed_at'] >= $opening['scheduled_start_at']) {
            $row['status'] = 'late_champion_evidence';

            return $row;
        }
        $trigger = $this->firstWin($triggerSeason, $triggerChampion['team_uid'], $asOf);
        $row['status'] = $trigger['status'];
        if (! isset($trigger['day'])) {
            return $row;
        }
        $row['trigger_day'] = $trigger['day'];
        if ($trigger['day'] < 4) {
            $row['status'] = 'not_qualified';

            return $row;
        }
        $firstDay = $strategy === 'champion-next-win' ? $trigger['day'] + 1 : 1;
        $readyAt = max($trigger['observed_at'], $champion['confirmed_at'], $triggerChampion['confirmed_at']);
        $matches = $this->teamMatches($current, $champion['team_uid']);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $day = $firstDay + $attempt - 1;
            $row['attempt'] = $attempt;
            if ($day > 30) {
                $row['status'] = 'window_ended';

                return $row;
            }
            $match = $matches[$day] ?? null;
            if (! $match || ! $match['scheduled_start_at']) {
                $row['status'] = 'missing_fixture';

                return $row;
            }
            if ($readyAt >= $match['scheduled_start_at']) {
                $row['status'] = 'late_evidence';

                return $row;
            }
            $row['next_match'] = $match;
            if (($match['result_observed_at'] ?? $asOf) <= $asOf && $match['status'] === 'settled') {
                if ($this->won($match, $champion['team_uid'])) {
                    $row['status'] = 'observed_win';

                    return $row;
                }
                $readyAt = max($readyAt, $match['result_observed_at']);

                continue;
            }
            $row['status'] = $match['scheduled_start_at'] <= $asOf ? 'awaiting_result' : 'watching_fixture';

            return $row;
        }
        $row['status'] = 'four_attempts_without_win';

        return $row;
    }

    private function linked(?array $before, array $after, array $links, string $asOf): bool
    {
        foreach ($links as $link) {
            if ($link['from_season_id'] === ($before['season_id'] ?? null) && $link['to_season_id'] === $after['season_id']
                && $link['observed_at'] <= $asOf && ($link['continuity_status'] ?? '') === 'observed_rollover') {
                return true;
            }
        }

        return false;
    }

    private function firstWin(array $season, string $uid, string $asOf): array
    {
        $matches = $this->teamMatches($season, $uid);
        $observed = '';
        for ($day = 1; $day <= 30; $day++) {
            $match = $matches[$day] ?? null;
            if (! $match || $match['status'] !== 'settled' || ! isset($match['result_observed_at']) || $match['result_observed_at'] > $asOf) {
                return ['status' => 'awaiting_first_win'];
            }
            $observed = max($observed, $match['result_observed_at']);
            if ($this->won($match, $uid)) {
                return ['status' => 'qualified', 'day' => $day, 'observed_at' => $observed];
            }
        }

        return ['status' => 'no_win_in_season'];
    }

    private function teamMatches(array $season, string $uid): array
    {
        $rows = [];
        foreach ($season['matches'] as $match) {
            if ($match['home_team_uid'] === $uid || $match['away_team_uid'] === $uid) {
                if (isset($rows[$match['matchday']])) {
                    return []; // Ambiguous fixture history must not produce a candidate.
                }
                $rows[$match['matchday']] = $match;
            }
        }

        return $rows;
    }

    private function won(array $match, string $uid): bool
    {
        return $match['result'] === ($match['home_team_uid'] === $uid ? 'H' : 'A');
    }
}
