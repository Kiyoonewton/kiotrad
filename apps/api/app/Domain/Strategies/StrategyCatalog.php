<?php

namespace App\Domain\Strategies;

use App\Domain\Paper\PaperLedger;

final class StrategyCatalog
{
    public function all(): array
    {
        $common = [
            'version' => ChampionMonitor::SIMULATION_VERSION,
            'recorded_decision_version' => ChampionMonitor::VERSION,
            'status' => 'simulation',
            'monitoring_enabled' => true,
            'bankroll_enabled' => false,
            'paper_policy_version' => PaperLedger::VERSION,
            'rules' => [
                'irregular_first_win_day_min' => ChampionMonitor::MIN_FIRST_WIN_DAY,
                'max_attempts' => 4,
                'minimum_stake_minor' => 10000,
                'profit_per_attempt_minor' => 10000,
                'recovery' => 'prior_losses_plus_attempt_times_profit',
                'stop_at_first_win' => true,
            ],
            'unresolved' => [
                'Research simulations replay collected events in matchday order, including recovered data.',
                'Recorded historical odds are retained with their collection times; unverified pre-match availability is noted.',
                'Independent runs can overlap and do not establish returns from one shared tradable portfolio.',
            ],
        ];

        return [
            array_merge($common, [
                'id' => 'champion-next-season',
                'name' => 'Champion — Next Season',
                'description' => 'After the previous champion first wins on matchday 5 or later in one season, follow the next season’s own previous champion for its first win, for up to four attempts.',
                'entry' => 'Following season, matchday 1; preceding transition must qualify.',
            ]),
            array_merge($common, [
                'id' => 'champion-next-win',
                'name' => 'Champion — Next Win',
                'description' => 'When the previous champion first wins on matchday 5 or later, follow the same team for its next win, for up to four attempts.',
                'entry' => 'Next fixture after the qualifying first win has settled.',
            ]),
        ];
    }
}
