<?php

namespace App\Domain\Research;

use DateTimeImmutable;
use DomainException;

final class SettlementTimingModel
{
    public const VERSION = 'scheduled-kickoff-offset-1.0.0';

    public function apply(array $candidates, string $asOf, ?int $seconds): array
    {
        if ($seconds === null) {
            return $candidates;
        }
        if (! in_array($seconds, [180, 201, 220], true)) {
            throw new DomainException('unsupported_settlement_model');
        }
        foreach ($candidates as &$candidate) {
            foreach ($candidate['fixtures'] as &$fixture) {
                // Model event order only when a genuine result exists in evidence by asOf.
                $observed = $fixture['result_confirmed_at'] ?? null;
                $fixture['simulation_settlement_at'] = null;
                if ($observed !== null && new DateTimeImmutable($observed) >= new DateTimeImmutable($fixture['scheduled_start_at'])
                    && new DateTimeImmutable($observed) <= new DateTimeImmutable($asOf)
                    && is_bool($fixture['won'] ?? null)) {
                    $fixture['simulation_settlement_at'] = (new DateTimeImmutable($fixture['scheduled_start_at']))
                        ->modify('+'.$seconds.' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
                }
            }
            unset($fixture);
        }
        unset($candidate);

        return $candidates;
    }
}
