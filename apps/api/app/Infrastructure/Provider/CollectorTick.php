<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final class CollectorTick
{
    public function __construct(
        private ProviderProbe $probe,
        private CollectionStore $store,
        private CollectorState $state,
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
        try {
            $report = $this->probe->run($competition);
            $this->store->save($report);
        } catch (Throwable $exception) {
            $failures = min(10, (int) ($previous['failure_count'] ?? 0) + 1);
            $delay = min(300, 45 * (2 ** ($failures - 1)));
            $this->state->write($competition, [
                'failure_count' => $failures,
                'last_failure_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'next_attempt_at' => CarbonImmutable::now('UTC')->addSeconds($delay)->toIso8601String(),
            ]);
            report($exception);

            return 'retrying';
        }
        $this->state->write($competition, [
            'failure_count' => 0,
            'last_failure_at' => null,
            'next_attempt_at' => CarbonImmutable::now('UTC')->addSeconds(45)->toIso8601String(),
        ]);

        return 'collected';
    }
}
