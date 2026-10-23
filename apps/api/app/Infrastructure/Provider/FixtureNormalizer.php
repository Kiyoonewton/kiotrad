<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use RuntimeException;

final class FixtureNormalizer
{
    public function normalize(array $season, string $seasonId, int $tournament): array
    {
        if ((string) ($season['_id'] ?? '') !== $seasonId || (int) ($season['_utid'] ?? 0) !== $tournament
            || ! is_array($season['matches'] ?? null) || $season['matches'] === []) {
            throw new RuntimeException('Fixtures do not match the requested season and league.');
        }
        $matches = [];
        foreach ($season['matches'] as $match) {
            $id = (string) ($match['_id'] ?? '');
            if (! ctype_digit($id) || isset($matches[$id])
                || (string) ($match['_seasonid'] ?? '') !== $seasonId
                || (int) ($match['_utid'] ?? 0) !== $tournament) {
                throw new RuntimeException('Invalid, duplicate or cross-league fixture identity.');
            }
            $home = $match['teams']['home'] ?? [];
            $away = $match['teams']['away'] ?? [];
            if (empty($home['uid']) || empty($away['uid']) || $home['uid'] === $away['uid']
                || empty($home['name']) || empty($away['name'])) {
                throw new RuntimeException('Fixture team identities are missing or conflicting.');
            }
            $round = filter_var($match['round'] ?? null, FILTER_VALIDATE_INT);
            if ($round === false || $round < 1 || $round > 30) {
                throw new RuntimeException('Fixture round is outside the verified league format.');
            }
            $cancelled = ! empty($match['canceled']) || ! empty($match['postponed']);
            $ft = $match['periods']['ft'] ?? null;
            $settled = ! $cancelled && is_array($ft)
                && isset($ft['home'], $ft['away'])
                && is_int($ft['home']) && is_int($ft['away']) && $ft['home'] >= 0 && $ft['away'] >= 0;
            $result = $settled ? ($ft['home'] > $ft['away'] ? 'H' : ($ft['home'] < $ft['away'] ? 'A' : 'D')) : null;
            $scheduled = $this->scheduledTime($match);
            $matches[$id] = [
                'provider_match_id' => "vf:match:{$id}",
                'season_id' => $seasonId,
                'matchday' => $round,
                'home_team_uid' => (string) $home['uid'],
                'home_team' => $home['name'],
                'away_team_uid' => (string) $away['uid'],
                'away_team' => $away['name'],
                'scheduled_start_at' => $scheduled?->toIso8601String(),
                'time_source' => $scheduled ? 'stats_season_fixtures2.time.uts' : null,
                'actual_start_at' => null,
                'status' => $cancelled ? 'unavailable' : ($settled ? 'settled' : 'scheduled_or_unconfirmed'),
                'home_score' => $settled ? $ft['home'] : null,
                'away_score' => $settled ? $ft['away'] : null,
                'result' => $result,
            ];
        }

        return array_values($matches);
    }

    private function scheduledTime(array $match): ?CarbonImmutable
    {
        // Unknown/announced-later times are preserved as unknown, never filled from response time.
        if (! empty($match['tobeannounced'])) {
            return null;
        }
        $timestamp = $match['time']['uts'] ?? null;
        if (! is_int($timestamp) || $timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }
}
