<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final class CollectorTick
{
    public function __construct(
        private CollectionCycle $cycle,
        private CollectorState $state,
        private CollectionGap $gaps,
        private ResultRepairRequests $repairs,
    ) {}

    public function run(string $competition): string
    {
        if (! array_key_exists($competition, config('provider.competitions'))) {
            throw new InvalidArgumentException('Unknown competition.');
        }
        $previous = $this->state->read($competition);
        $now = CarbonImmutable::now('UTC');
        if (isset($previous['next_attempt_at']) && $now->lessThan(CarbonImmutable::parse($previous['next_attempt_at']))) {
            return 'waiting';
        }
        $gap = $this->gaps->detect($competition, $previous, $now->toIso8601String(), false);
        if ($gap !== null && ! isset($previous['collection_gap'])) {
            $this->state->write($competition, ['collection_gap' => $gap]);
            $previous['collection_gap'] = $gap;
        }
        try {
            $requests = $this->repairs->pending($competition);
            $urgent = [];
            foreach ($requests as $season) {
                $urgent[$season] = $previous['pending_seasons'][$season] ?? ['next_attempt_at' => $now->toIso8601String(), 'failures' => 0, 'reason' => 'paper_result_missing'];
            }
            if ($urgent !== []) {
                $previous['pending_seasons'] = $urgent + ($previous['pending_seasons'] ?? []);
            }
            $schedule = $this->cycle->run($competition, $previous);
            foreach ($requests as $season) {
                if (! $this->repairs->complete($competition, $season) && $season !== ($schedule['current_season_id'] ?? null)) {
                    $retry = CarbonImmutable::now('UTC')->addMinutes(5)->toIso8601String();
                    $schedule['pending_seasons'][$season] ??= ['next_attempt_at' => $retry, 'failures' => 1, 'reason' => 'paid_match_unconfirmed'];
                    $schedule['next_attempt_at'] = min($schedule['next_attempt_at'], $retry);
                }
            }

        } catch (Throwable $exception) {
            $gap = $this->gaps->detect($competition, $previous, CarbonImmutable::now('UTC')->toIso8601String(), true);
            $failures = min(10, (int) ($previous['failure_count'] ?? 0) + 1);
            $delay = min(300, 45 * (2 ** ($failures - 1)));
            $this->state->write($competition, [
                'collection_gap' => $gap,
                'failure_count' => $failures,
                'last_failure_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'next_attempt_at' => CarbonImmutable::now('UTC')->addSeconds($delay)->toIso8601String(),
            ]);
            report($exception);

            return 'retrying';
        }
        $gapRecord = $gap ?? $previous['last_collection_gap'] ?? null;
        $gapState = $gapRecord === null ? [] : ['collection_gap' => null,
            'last_collection_gap' => $this->gaps->resumed($gapRecord, $schedule, CarbonImmutable::now('UTC')->toIso8601String())];
        $this->state->write($competition, [
            ...$schedule,
            ...$gapState,
            'failure_count' => 0,
            'last_failure_at' => null,
        ]);

        return 'collected';
    }
}
