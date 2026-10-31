<?php

namespace App\Infrastructure\Research;

use App\Domain\Research\ResearchScenario;

final class EstimatedHistoryComparison
{
    public const VERSION = 'estimated-history-comparison-1.0.0';

    public function calculate(array $recorded, array $additional, array $settings, array $provenance): array
    {
        $engine = new ResearchScenario;
        $baseline = $engine->calculate($recorded, $settings);
        $combined = $recorded;
        $ids = array_fill_keys(array_column($recorded['candidates'], 'id'), true);
        $duplicates = [];
        foreach ($additional['candidates'] as $candidate) {
            if (isset($ids[$candidate['id']])) {
                $duplicates[] = $candidate['id'];

                continue; // Existing recorded candidate wins, unchanged.
            }
            $ids[$candidate['id']] = true;
            $combined['candidates'][] = $candidate;
        }
        $combined['excluded'] = array_values(array_filter([...$combined['excluded'], ...$additional['excluded']],
            fn ($r) => ! isset($ids[$r['id'] ?? implode(':', [$r['competition'], $r['season_id'], $r['strategy_id']])])));
        $hypothesis = $engine->calculate($combined, $settings);
        $byId = array_column($hypothesis['runs'], null, 'id');
        foreach ($hypothesis['runs'] as &$run) {
            if (($run['estimated_timing_dependency'] ?? false) || ($byId[$run['blocked_by'] ?? '']['estimated_timing_dependency'] ?? false)) {
                $run['overlap_basis'] = 'hypothetical_kickoff_and_modeled_settlement';
            }
        }
        unset($run);
        $accepted = fn ($view) => array_column($view['equity'], 'run_id');

        return ['version' => self::VERSION, 'research_only' => true, 'benchmark_eligible' => false,
            'provenance' => $provenance, 'settings' => $settings,
            'limits' => ['Earlier dates have unknown uncertainty; overlap is conditional on these dates.',
                'This is a retrospective research scenario, not proof of live profitability or available pre-match prices.',
                'Existing recorded candidates and their sizing minima are held unchanged for this controlled comparison.',
                'New prefix/boundary candidates use only earlier loaded seasons for sizing minima.',
                'Recorded-only means the current benchmark without proposed kickoff dates; recovered odds may already be included.',
                'Compounding can alter later recorded-run stakes; profit difference is not simply the sum of older-run profits.'],
            'recorded_only' => $baseline, 'with_estimated_history' => $hypothesis,
            'difference' => ['balance_minor' => $hypothesis['balance_minor'] - $baseline['balance_minor'],
                'accepted_only_with_estimates' => array_values(array_diff($accepted($hypothesis), $accepted($baseline))),
                'accepted_only_recorded' => array_values(array_diff($accepted($baseline), $accepted($hypothesis)))],
            'existing_candidates_preserved' => $duplicates, 'estimated_history_exclusions' => $additional['excluded']];
    }
}
