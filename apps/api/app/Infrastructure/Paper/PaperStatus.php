<?php

namespace App\Infrastructure\Paper;

use Throwable;

class PaperStatus
{
    public function __construct(private PaperStore $store) {}

    public function overview(): array
    {
        $base = ['bankroll' => ['status' => 'not_configured', 'starting_balance_minor' => null, 'balance_minor' => null,
            'committed_minor' => null, 'available_minor' => null, 'net_profit_minor' => null, 'target_balance_minor' => null],
            'active_run' => null, 'capital_history' => [], 'recent_runs' => []];
        try {
            $state = $this->store->read();
        } catch (Throwable $exception) {
            report($exception);
            $base['bankroll']['status'] = 'unavailable';

            return $base;
        }
        if ($state === null) {
            return $base;
        }
        $equity = $state['cash_minor'] + $state['committed_minor'];

        return ['bankroll' => ['status' => 'paper', 'starting_balance_minor' => $state['starting_balance_minor'],
            'balance_minor' => $equity, 'available_minor' => $state['cash_minor'], 'committed_minor' => $state['committed_minor'],
            'net_profit_minor' => $equity - $state['starting_balance_minor'], 'target_balance_minor' => $state['target_balance_minor'],
            ...$this->milestones($state, $equity)],
            'active_run' => $state['active_run'], 'capital_history' => $state['capital_history'],
            'recent_runs' => array_slice(array_reverse($state['runs']), 0, 20),
            'paper_policy_version' => $state['policy_version'], 'paper_journal_full' => count($state['runs']) >= 250 || count($state['decisions']) >= 2000];
    }

    private function milestones(array $state, int $equity): array
    {
        $step = $state['target_balance_minor'];
        $peak = max($equity, $state['starting_balance_minor'], ...array_column($state['capital_history'], 'balance_minor'));
        $count = $step === null ? 0 : intdiv($peak, $step);

        return ['milestone_step_minor' => $step, 'milestones_reached' => $count,
            'next_milestone_minor' => $step === null ? null : ($count + 1) * $step];
    }
}
