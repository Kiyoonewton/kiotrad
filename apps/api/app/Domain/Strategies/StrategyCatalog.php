<?php

namespace App\Domain\Strategies;

final class StrategyCatalog
{
    public function all(): array
    {
        $common = [
            'version' => '0.1.0-draft',
            'status' => 'research',
            'monitoring_enabled' => false,
            'bankroll_enabled' => false,
            'rules' => [
                'irregular_first_win_day_min' => 4,
                'max_attempts' => 4,
                'minimum_stake_minor' => 10000,
                'profit_per_attempt_minor' => 10000,
                'recovery' => 'prior_losses_plus_attempt_times_profit',
                'stop_at_first_win' => true,
            ],
            'unresolved' => [
                'Official champion tiebreaks and score availability.',
                'Treatment of no first win in the first ten matches.',
                'Season continuity and complete matchday criteria.',
            ],
        ];

        return [
            array_merge($common, [
                'id' => 'champion-next-season',
                'name' => 'Champion — Next Season',
                'description' => 'After a delayed first win in one season, follow the next season’s own previous champion for its first win, for up to four attempts.',
                'entry' => 'Following season, matchday 1; preceding transition must qualify.',
            ]),
            array_merge($common, [
                'id' => 'champion-next-win',
                'name' => 'Champion — Next Win',
                'description' => 'When the previous champion first wins on matchday 4 or later, follow the same team for its next win, for up to four attempts.',
                'entry' => 'Next fixture after the qualifying first win has settled.',
            ]),
        ];
    }
}
