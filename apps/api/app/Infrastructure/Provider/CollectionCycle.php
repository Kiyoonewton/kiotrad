<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class CollectionCycle
{
    public function __construct(
        private SeasonDiscovery $discovery,
        private SeasonCapture $capture,
        private CollectionStore $store,
    ) {}

    public function run(string $competition, array $previous): array
    {
        $discovery = $this->discovery->discover(config("provider.competitions.{$competition}"));
        $currentId = $discovery['season_id'];
        $profile = null;
        foreach ($discovery['seasons'] as $season) {
            if ((string) $season['_id'] === $currentId) {
                $profile = $season;
            }
        }
        $start = $profile['start']['uts'] ?? null;
        if (($previous['current_season_id'] ?? $currentId) !== $currentId && is_int($start)
            && isset($previous['current_season_start_uts']) && $start < $previous['current_season_start_uts']) {
            throw new RuntimeException('Season discovery went backwards; awaiting fresh metadata.');
        }
        $keys = $previous['captured_round_keys'] ?? [];
        $pending = $previous['pending_seasons'] ?? [];
        $old = $previous['current_season_id'] ?? null;
        if ($old !== null && $old !== $currentId && ! ($previous['last_results_complete'] ?? false)) {
            $pending[$old] ??= ['next_attempt_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'failures' => 0];
        }
        unset($pending[$currentId]);
        $report = $this->capture->run($competition, $currentId, 'current', $keys);
        $this->store->save($report);
        $keys = array_values(array_unique([...$keys, ...$report['captured_round_keys']]));
        $due = $this->deadlines($report);
        $following = array_values(array_filter($discovery['seasons'], fn ($s) => is_int($start)
            && is_int($s['start']['uts'] ?? null) && $s['start']['uts'] > $start));
        usort($following, fn ($a, $b) => $a['start']['uts'] <=> $b['start']['uts']);
        $previewId = null;
        $marketObservation = $report['market_observation'];
        $planned = array_map(fn ($r) => ['season_id' => $currentId, ...$r], $report['odds_coverage']);
        if (count($report['odds_matchdays']) < 2 && isset($following[0])) {
            $previewId = (string) $following[0]['_id'];
            try {
                $preview = $this->capture->run($competition, $previewId, 'preview', $keys, 2 - count($report['odds_matchdays']));
                $this->store->save($preview);
                $keys = array_values(array_unique([...$keys, ...$preview['captured_round_keys']]));
                $planned = [...$planned, ...array_map(fn ($r) => ['season_id' => $previewId, ...$r], $preview['odds_coverage'])];
                if ($preview['market_observation'] === 'availability_unknown') {
                    $marketObservation = 'availability_unknown';
                }
                $due = [...$due, ...$this->deadlines($preview)];
            } catch (Throwable $exception) {
                report($exception);
                $marketObservation = 'availability_unknown';
                $due[] = CarbonImmutable::now('UTC')->addSeconds(45);
            }
        }
        // Process at most one old season per cycle so an inaccessible archive cannot starve current data.
        foreach ($pending as $id => &$job) {
            if (CarbonImmutable::parse($job['next_attempt_at'])->isFuture()) {
                continue;
            }
            try {
                $repaired = $this->capture->run($competition, (string) $id, 'reconciliation', [], 0);
                $this->store->save($repaired);
                if ($repaired['completeness']['results_complete']) {
                    unset($pending[$id]);

                    break;
                }
                $job['reason'] = 'results_or_fixtures_missing';
                $job['completeness'] = $repaired['completeness'];
            } catch (Throwable $exception) {
                report($exception);
                $job['reason'] = 'fetch_failed';
            }
            $job['failures'] = min(8, ($job['failures'] ?? 0) + 1);
            $job['next_attempt_at'] = CarbonImmutable::now('UTC')->addSeconds(min(3600, 45 * (2 ** $job['failures'])))->toIso8601String();

            break;
        }
        unset($job);
        foreach ($pending as $job) {
            $due[] = CarbonImmutable::parse($job['next_attempt_at']);
        }
        $end = $profile['end']['uts'] ?? null;
        $now = CarbonImmutable::now('UTC');
        if (is_int($end) && $end > $now->getTimestamp()) {
            $due[] = CarbonImmutable::createFromTimestampUTC($end)->addSecond();
        } elseif (is_int($end) || $due === []) {
            $due[] = $now->addSeconds(45);
        }
        $due = array_map(fn ($d) => $d->greaterThan($now) ? $d : $now->addSeconds(30), $due);
        usort($due, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $retained = [$currentId, $previewId, ...array_map('strval', array_keys($pending))];

        return [
            'current_season_id' => $currentId,
            'current_season_start_uts' => $start,
            'last_results_complete' => $report['completeness']['results_complete'],
            'pending_seasons' => $pending,
            'captured_round_keys' => array_values(array_filter($keys, fn ($k) => in_array(explode(':', $k)[0], $retained, true))),
            'next_attempt_at' => $due[0]->toIso8601String(),
            'market_observation' => $marketObservation,
            'planned_rounds' => $planned,
        ];
    }

    private function deadlines(array $report): array
    {
        $due = [];
        if ($report['next_scheduled_start_at'] !== null) {
            $due[] = CarbonImmutable::parse($report['next_scheduled_start_at'])->addSecond();
        }
        if ($report['result_due_at'] !== null) {
            $due[] = CarbonImmutable::parse($report['result_due_at']);
        }
        if (! $report['completeness']['fixtures_complete'] || $report['market_observation'] === 'availability_unknown') {
            $due[] = CarbonImmutable::now('UTC')->addSeconds(45);
        }

        return $due;
    }
}
