<?php

namespace App\Infrastructure\Paper;

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\ResultRepairRequests;
use Carbon\CarbonImmutable;

final class PaperRunner
{
    public function __construct(private PaperStore $store, private ChampionMonitoring $monitoring, private PaperMarket $market, private PaperLedger $ledger, private ResultRepairRequests $repairs) {}

    public function tick(): string
    {
        $state = $this->store->read();
        if ($state === null) {
            return 'not_configured';
        }
        $now = now('UTC')->toIso8601String();
        $candidates = $this->monitoring->all(false);
        usort($candidates, fn ($a, $b) => [$a['competition'], $a['strategy_id'] ?? ''] <=> [$b['competition'], $b['strategy_id'] ?? '']);
        $requests = [];
        if ($run = $state['active_run']) {
            $last = $run['attempts'] === [] ? null : $run['attempts'][array_key_last($run['attempts'])];
            $requests[] = ['competition' => $run['competition'], 'season_id' => $run['season_id'], 'team_uid' => $run['team_uid'],
                'day' => $last && $last['status'] === 'pending' ? $last['matchday'] : $run['first_day'] + count($run['attempts'])];
        }
        foreach ($candidates as $candidate) {
            if ($candidate['status'] === 'watching_fixture' && isset($candidate['team_uid'])) {
                $requests[] = ['competition' => $candidate['competition'], 'season_id' => $candidate['season_id'],
                    'team_uid' => $candidate['team_uid'], 'day' => $candidate['next_match']['matchday']];
            }
        }
        $facts = $this->market->facts($requests, $now);
        $now = now('UTC')->toIso8601String();
        if (isset($last) && $last['status'] === 'pending') {
            $key = implode(':', [$run['competition'], $run['season_id'], $run['team_uid'], $last['matchday']]);
            $result = $facts[$key]['fixture'] ?? null;
            if (($result === null || $result['status'] !== 'settled' || $result['provider_match_id'] !== $last['provider_match_id'] || ! in_array($result['result'] ?? null, ['H', 'D', 'A'], true))
                && CarbonImmutable::parse($last['scheduled_start_at'])->addSeconds(config('provider.result_check_delay_seconds') + 60)->lessThanOrEqualTo(CarbonImmutable::parse($now))) {
                $this->repairs->request($run['competition'], $run['season_id'], $last['provider_match_id']);
            }
        }
        $next = $this->ledger->advance($state, $candidates, $facts, $now);
        if ($next === $state) {
            return 'waiting';
        }

        return $this->store->replace($state['revision'], $next) ? 'updated' : 'revision_changed';
    }
}
