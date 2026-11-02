<?php

namespace App\Domain\Research;

final class ResearchScenario
{
    public const VERSION = 'prepared-per-strategy-carry-2.0.0';

    public function calculate(array $prepared, array $settings): array
    {
        $candidates = $excluded = $unqualified = [];
        $rules = static function (string $id) use ($settings): array {
            $prefix = $id === 'champion-next-win' ? 'next_win' : 'next_season';

            return ['qualification' => $settings[$prefix.'_qualification'] ?? $settings['qualification'],
                'attempts' => $settings[$prefix.'_attempts'] ?? $settings['attempts']];
        };
        $eligible = fn ($r) => ($r['trigger_day'] ?? 0) >= $rules($r['strategy_id'])['qualification']
            && ($settings['strategy'] === 'all' || $r['strategy_id'] === $settings['strategy'])
            && ($settings['league'] === 'all' || $r['competition'] === $settings['league']);
        foreach ($prepared['excluded'] as $row) {
            if ($eligible($row)) {
                $excluded[] = $row;
            }
        }
        foreach ($prepared['candidates'] as $candidate) {
            if (! $eligible($candidate)) {
                if (($settings['strategy'] === 'all' || $candidate['strategy_id'] === $settings['strategy'])
                    && ($settings['league'] === 'all' || $candidate['competition'] === $settings['league'])) {
                    $unqualified[] = [...array_intersect_key($candidate, array_flip(['id', 'competition', 'season_id', 'strategy_id', 'trigger_day', 'team'])),
                        'status' => 'not_qualified', 'entry_at' => $candidate['fixtures'][0]['scheduled_start_at'] ?? ''];
                }

                continue;
            }
            $candidate['attempt_limit'] = $rules($candidate['strategy_id'])['attempts'];
            $candidate['fixtures'] = array_slice($candidate['fixtures'], 0, $candidate['attempt_limit']);
            if (! $candidate['fixtures'] || array_filter($candidate['fixtures'], fn ($f) => empty($f['scheduled_start_at']))) {
                $excluded[] = [...array_intersect_key($candidate, array_flip(['id', 'competition', 'season_id', 'strategy_id', 'trigger_day', 'team'])),
                    'reason' => 'missing_fixture_or_schedule'];

                continue;
            }
            if (($settings['staking_model'] ?? 'recovery') === 'payout_carry_v1'
                && (count($candidate['fixtures']) < $candidate['attempt_limit']
                    || array_filter($candidate['fixtures'], fn ($f) => $f['scheduled_start_at'] > $prepared['as_of']))) {
                $excluded[] = [...array_intersect_key($candidate, array_flip(['id', 'competition', 'season_id', 'strategy_id', 'trigger_day', 'team'])),
                    'reason' => 'incomplete_retrospective_window'];

                continue;
            }
            $candidates[] = $candidate;
        }
        $view = (new TimingSensitivityReplay)->calculate($candidates, $settings['capital'] * 100,
            $prepared['as_of'], max($settings['attempts'], $settings['next_win_attempts'] ?? 0, $settings['next_season_attempts'] ?? 0), $settings['allowance'],
            $settings['capital_mode'] ?? 'compound', ($settings['profit_stop'] ?? 0) * 100, $settings['staking_model'] ?? 'recovery');

        return ['status' => 'available', ...$view, 'excluded' => $excluded, 'unqualified' => $unqualified,
            'seasons_assessed' => $prepared['seasons_assessed'], 'settings' => $settings,
            'scenario_version' => self::VERSION, 'source_as_of' => $prepared['as_of']];
    }
}
