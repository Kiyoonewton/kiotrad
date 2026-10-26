<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use RuntimeException;

final class OddsNormalizer
{
    public function normalize(FeedObservation $observation, array $fixtures, int $tournament, int $round): array
    {
        $byId = array_column($fixtures, null, 'provider_match_id');
        $events = $observation->document['data']['odds'] ?? [];
        if (! is_array($events)) {
            throw new RuntimeException('Invalid odds collection.');
        }
        $rows = [];
        $seen = [];
        foreach ($events as $event) {
            $id = $event['id'] ?? '';
            $fixture = $byId[$id] ?? null;
            if (! $fixture || isset($seen[$id]) || $fixture['matchday'] !== $round
                || ($event['tournament_id'] ?? '') !== "vf:tournament:{$tournament}"
                || ($event['teams']['home']['id'] ?? '') !== 'sr:competitor:'.$fixture['home_team_uid']
                || ($event['teams']['away']['id'] ?? '') !== 'sr:competitor:'.$fixture['away_team_uid']) {
                throw new RuntimeException('Odds cannot be joined unambiguously to the requested league and fixture.');
            }
            $seen[$id] = true;
            $markets = array_values(array_filter($event['market'] ?? [], fn ($m) => (string) ($m['id'] ?? '') === '1'));
            if (count($markets) !== 1) {
                continue;
            }
            $market = $markets[0];
            $prices = [];
            $active = (int) ($market['status'] ?? 0) === 1;
            foreach ($market['outcome'] ?? [] as $outcome) {
                $code = ['1' => 'H', '2' => 'D', '3' => 'A'][(string) ($outcome['id'] ?? '')] ?? null;
                $price = (string) ($outcome['odds'] ?? '');
                if (! $code || isset($prices[$code]) || ! preg_match('/^\d+(?:\.\d+)?$/D', $price)
                    || ! is_finite((float) $price) || (float) $price <= 1) {
                    throw new RuntimeException('Invalid or duplicate 1X2 outcome.');
                }
                $prices[$code] = $price;
                $active = $active && (int) ($outcome['active'] ?? 0) === 1 && (int) ($outcome['result'] ?? -2) === -1;
            }
            if (count($prices) !== 3) {
                continue;
            }
            $beforeStart = $fixture['scheduled_start_at'] !== null
                && $observation->receivedAt->lessThan(CarbonImmutable::parse($fixture['scheduled_start_at']));
            $rows[] = [
                'provider_match_id' => $id,
                'market' => '1X2',
                'prices' => $prices,
                'captured_at' => $observation->receivedAt->toIso8601String(),
                'provider_response_timestamp_ms' => $observation->document['timestamp'] ?? null,
                'pre_match_verified' => $observation->provenance === [] && $beforeStart && $active && $fixture['status'] === 'scheduled_or_unconfirmed',
                'market_status' => $market['status'] ?? null,
                ...($observation->provenance === [] ? [] : ['provenance' => $observation->provenance]),
            ];
        }

        return $rows;
    }
}
