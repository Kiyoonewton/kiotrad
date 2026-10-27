<?php

namespace App\Domain\Strategies;

final class ChampionMonitor
{
    public const VERSION = '0.3.0-observation-day5';

    public const SIMULATION_VERSION = '0.4.0-event-simulation-day5';

    public const MIN_FIRST_WIN_DAY = 5;

    public function evaluate(string $strategy, array $current, ?array $prior, ?array $older, array $links, string $asOf, bool $eventOrder = false, int $qualification = self::MIN_FIRST_WIN_DAY, int $maxAttempts = 4): array
    {
        $row = ['strategy_id' => $strategy, 'version' => $eventOrder ? self::SIMULATION_VERSION : self::VERSION, 'season_id' => $current['season_id'] ?? null,
            'recovered' => false, 'data_quality_notes' => [], 'timing_basis' => $eventOrder ? 'event_order' : 'as_observed',
            'status' => 'waiting_for_history', 'team' => null, 'trigger_day' => null, 'attempt' => null,
            'next_match' => null, 'execution_enabled' => false, 'evaluated_at' => $asOf];
        $champion = $prior['champion'] ?? null;
        if ($prior === null) {
            return [...$row, 'reason' => 'missing_linked_previous_season'];
        }
        if (! $champion) {
            return [...$row, 'reason' => 'previous_champion_unverified'];
        }
        if (! $this->linked($prior, $current, $links, $asOf, $eventOrder)) {
            return [...$row, 'reason' => 'season_link_not_verified'];
        }
        $row['team'] = $champion['team_name'];
        $row['team_uid'] = $champion['team_uid'];
        $triggerSeason = $strategy === 'champion-next-win' ? $current : $prior;
        $triggerChampion = $strategy === 'champion-next-win' ? $champion : ($older['champion'] ?? null);
        if (! $triggerChampion) {
            return [...$row, 'reason' => $older === null ? 'missing_linked_older_season' : 'older_champion_unverified'];
        }
        if ($strategy === 'champion-next-season' && ! $this->linked($older, $prior, $links, $asOf, $eventOrder)) {
            return [...$row, 'reason' => 'earlier_season_link_not_verified'];
        }
        $opening = $this->teamMatches($triggerSeason, $triggerChampion['team_uid'])[1] ?? null;
        if (! $opening || ! $opening['scheduled_start_at'] || (! $eventOrder && $triggerChampion['confirmed_at'] >= $opening['scheduled_start_at'])) {
            $row['status'] = $eventOrder ? 'missing_fixture' : 'late_champion_evidence';

            return $row;
        }
        if ($eventOrder && $triggerChampion['confirmed_at'] >= $opening['scheduled_start_at']) {
            $row['recovered'] = true;
            $row['data_quality_notes'][] = 'Champion confirmation arrived after season opening; replay uses season order.';
        }
        $trigger = $this->firstWin($triggerSeason, $triggerChampion['team_uid'], $asOf);
        $row['status'] = $trigger['status'];
        if (! isset($trigger['day'])) {
            return $row;
        }
        $row['trigger_day'] = $trigger['day'];
        if ($trigger['day'] < $qualification) {
            $row['status'] = 'not_qualified';

            return $row;
        }
        $firstDay = $strategy === 'champion-next-win' ? $trigger['day'] + 1 : 1;
        $readyAt = max($trigger['observed_at'], $champion['confirmed_at'], $triggerChampion['confirmed_at']);
        foreach ($links as $link) {
            if ($link['from_season_id'] === $prior['season_id'] && $link['to_season_id'] === $current['season_id']) {
                $readyAt = max($readyAt, $link['observed_at']);
            }
        }
        $row['evidence_ready_at'] = $readyAt;
        $matches = $this->teamMatches($current, $champion['team_uid']);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
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
            if (! $eventOrder && $readyAt >= $match['scheduled_start_at']) {
                $row['status'] = 'late_evidence';

                return $row;
            }
            if ($eventOrder && $readyAt >= $match['scheduled_start_at']) {
                $row['recovered'] = true;
                $row['data_quality_notes'] = array_values(array_unique([...$row['data_quality_notes'],
                    'Collection was delayed; simulation follows the original matchday order.']));
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

    private function linked(?array $before, array $after, array $links, string $asOf, bool $eventOrder): bool
    {
        foreach ($links as $link) {
            if ($link['from_season_id'] === ($before['season_id'] ?? null) && $link['to_season_id'] === $after['season_id']
                && $link['observed_at'] <= $asOf && in_array($link['continuity_status'] ?? '',
                    $eventOrder ? ['observed_rollover', 'provider_catalog_adjacency'] : ['observed_rollover'], true)) {
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
