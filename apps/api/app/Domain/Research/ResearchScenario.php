<?php

namespace App\Domain\Research;

final class ResearchScenario
{
    public const VERSION = 'prepared-parameter-replay-1.0.0';

    public function calculate(array $prepared, array $settings): array
    {
        $candidates = $excluded = [];
        $eligible = fn ($r) => ($r['trigger_day'] ?? 0) >= $settings['qualification']
            && ($settings['strategy'] === 'all' || $r['strategy_id'] === $settings['strategy'])
            && ($settings['league'] === 'all' || $r['competition'] === $settings['league']);
        foreach ($prepared['excluded'] as $row) {
            if ($eligible($row)) {
                $excluded[] = $row;
            }
        }
        foreach ($prepared['candidates'] as $candidate) {
            if (! $eligible($candidate)) {
                continue;
            }
            $candidate['fixtures'] = array_slice($candidate['fixtures'], 0, $settings['attempts']);
            if (! $candidate['fixtures'] || array_filter($candidate['fixtures'], fn ($f) => empty($f['scheduled_start_at']))) {
                $excluded[] = [...array_intersect_key($candidate, array_flip(['id', 'competition', 'season_id', 'strategy_id', 'trigger_day', 'team'])),
                    'reason' => 'missing_fixture_or_schedule'];

                continue;
            }
            $candidates[] = $candidate;
        }
        $view = (new TimingSensitivityReplay)->calculate($candidates, $settings['capital'] * 100,
            $prepared['as_of'], $settings['attempts'], $settings['allowance']);

        return ['status' => 'available', ...$view, 'excluded' => $excluded,
            'seasons_assessed' => $prepared['seasons_assessed'], 'settings' => $settings,
            'scenario_version' => self::VERSION, 'source_as_of' => $prepared['as_of']];
    }
}
