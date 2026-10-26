<?php

namespace App\Domain\Research;

final class FixtureOddsBaseline
{
    public const VERSION = 'exact-venue-lowest-observed-1.0.0';

    public function fold(iterable $captures, string $competition, string $season): array
    {
        $pairs = [];
        $start = null;
        foreach ($captures as $capture) {
            $matches = [];
            foreach ($capture['matches'] ?? [] as $match) {
                if (! empty($match['scheduled_start_at'])) {
                    $start = $start === null ? $match['scheduled_start_at'] : min($start, $match['scheduled_start_at']);
                }
                $matches[$match['provider_match_id']] = $match;
            }
            foreach ($capture['odds_snapshots'] ?? [] as $price) {
                $match = $matches[$price['provider_match_id']] ?? null;
                if (! $match || $match['home_team_uid'] === $match['away_team_uid']) {
                    continue;
                }
                $key = $match['home_team_uid'].':'.$match['away_team_uid'];
                $pairs[$key] ??= ['competition' => $competition, 'season_id' => $season,
                    'home_team_uid' => $match['home_team_uid'], 'away_team_uid' => $match['away_team_uid'],
                    'home_team' => $match['home_team'], 'away_team' => $match['away_team'], 'minima' => []];
                foreach (['H', 'D', 'A'] as $outcome) {
                    $odds = (string) ($price['prices'][$outcome] ?? '');
                    if (! preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', $odds)) {
                        continue;
                    }
                    [$whole, $fraction] = array_pad(explode('.', $odds), 2, '');
                    $scaled = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
                    if ($scaled <= 10000 || $scaled > 10000000) {
                        continue;
                    }
                    $candidate = ['odds' => $odds, 'odds_scaled' => $scaled, 'capture_id' => $capture['_id'],
                        'provider_match_id' => $match['provider_match_id'], 'season_id' => $season,
                        'observed_at' => $price['captured_at'], 'scheduled_start_at' => $match['scheduled_start_at'] ?? null,
                        'pre_match_verified' => $price['pre_match_verified'] ?? false];
                    $old = $pairs[$key]['minima'][$outcome] ?? null;
                    if ($old === null || [$scaled, $candidate['observed_at'], $candidate['capture_id']]
                        < [$old['odds_scaled'], $old['observed_at'], $old['capture_id']]) {
                        $pairs[$key]['minima'][$outcome] = $candidate;
                    }
                }
            }
        }

        return array_values(array_map(fn ($pair) => [...$pair, 'season_start_at' => $start, 'policy_version' => self::VERSION], $pairs));
    }
}
