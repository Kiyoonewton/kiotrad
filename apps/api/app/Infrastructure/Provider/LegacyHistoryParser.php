<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class LegacyHistoryParser
{
    public function __construct(private LegacyOddsSource $source) {}

    public function parse(string $body, string $filename): array
    {
        $source = $this->source->decode($body, $filename, now('UTC')->toIso8601String());
        $events = $source['feed']->document['data']['odds'];
        $seen = [];
        $matches = [];
        foreach ($events as $event) {
            $id = $event['id'] ?? '';
            $tournament = config('provider.competitions.'.$source['competition'].'.tournament_uid');
            if (! preg_match('/^vf:match:\d+$/D', $id) || isset($seen[$id])
                || ($event['tournament_id'] ?? '') !== 'vf:tournament:'.$tournament) {
                throw new RuntimeException('Invalid, duplicate or mixed-league match identity.');
            }
            $seen[$id] = true;
            $teams = [];
            foreach (['home', 'away'] as $side) {
                $team = $event['teams'][$side] ?? [];
                if (! preg_match('/^sr:competitor:(\d+)$/D', $team['id'] ?? '', $uid)
                    || ! is_string($team['name'] ?? null) || trim($team['name']) === '') {
                    throw new RuntimeException('Missing team identity or name.');
                }
                $teams[$side.'_team_uid'] = $uid[1];
                $teams[$side.'_team'] = $team['name'];
            }
            if ($teams['home_team_uid'] === $teams['away_team_uid']) {
                throw new RuntimeException('Fixture has identical teams.');
            }
            $markets = array_values(array_filter($event['market'] ?? [], fn ($m) => (string) ($m['id'] ?? '') === '1'));
            if (count($markets) > 1) {
                throw new RuntimeException('Duplicate 1X2 markets.');
            }
            $prices = [];
            $winners = [];
            $outcomes = [];
            foreach ($markets[0]['outcome'] ?? [] as $outcome) {
                $code = ['1' => 'H', '2' => 'D', '3' => 'A'][(string) ($outcome['id'] ?? '')] ?? null;
                if ($code === null || isset($outcomes[$code])) {
                    throw new RuntimeException('Invalid or duplicate 1X2 outcome.');
                }
                $outcomes[$code] = true;
                $price = (string) ($outcome['odds'] ?? '');
                if (preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', $price) && (float) $price > 1 && (float) $price <= 1000) {
                    $prices[$code] = $price;
                }
                if ((string) ($outcome['result'] ?? '') === '1') {
                    $winners[] = $code;
                }
            }
            if (count($winners) > 1) {
                throw new RuntimeException('Multiple winning outcomes.');
            }
            $matches[] = ['provider_match_id' => $id, 'season_id' => $source['season_id'],
                'matchday' => $source['matchday'], ...$teams, 'scheduled_start_at' => null,
                'time_source' => null, 'actual_start_at' => null, 'home_score' => null, 'away_score' => null,
                'result' => $winners[0] ?? null, 'result_source' => $winners ? 'legacy_1x2_outcome' : null,
                'status' => $winners ? 'settled' : 'scheduled_or_unconfirmed',
                'prices' => count($prices) === 3 ? $prices : [],
                'price_status' => count($prices) === 3 ? 'available' : 'missing_or_invalid',
                'market_status' => $markets[0]['status'] ?? null];
        }

        return ['competition' => $source['competition'], 'season_id' => $source['season_id'],
            'matchday' => $source['matchday'], 'matches' => $matches,
            'source_sha256' => $source['provenance']['source_sha256'],
            'original_received_at' => null, 'timing_status' => 'unknown'];
    }
}
