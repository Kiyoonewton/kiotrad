<?php

namespace App\Infrastructure\Diagnostics;

use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\OddsNormalizer;
use App\Infrastructure\Provider\StandingsNormalizer;
use RuntimeException;

/** Read-only revalidation against present raw_rebuild fixture captures. */
final class RawDocumentValidator
{
    public function __construct(private FixtureNormalizer $fixtures, private OddsNormalizer $odds,
        private StandingsNormalizer $standings) {}

    public function validate($db, FeedObservation $feed, string $phase): string
    {
        $data = $feed->document['data'];
        if ($phase === 'catalogs') {
            return $this->catalog($data);
        }
        if ($phase === 'fixtures' && preg_match('~stats_season_fixtures2/(\d+)$~', $feed->url, $match)) {
            $league = $this->league((int) ($data['_utid'] ?? 0));
            if ($league === null) {
                return 'unknown_league';
            }
            $this->fixtures->normalize($data, $match[1], config("provider.competitions.{$league}.tournament_uid"));

            return 'validated';
        }
        $pattern = $phase === 'odds' ? '~vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$~' : '~stats_season_tables/(\d+)$~';
        if (! in_array($phase, ['odds', 'standings'], true) || ! preg_match($pattern, $feed->url, $match)) {
            return 'ignored_route';
        }
        $season = $match[1];
        $tournament = $phase === 'standings' ? (int) ($data['_utid'] ?? 0) :
            (int) str_replace('vf:tournament:', '', $data['odds'][0]['tournament_id'] ?? '');
        $league = $this->league($tournament);
        if ($league === null) {
            return 'unknown_league';
        }
        $query = ['competition' => $league, 'season_id' => $season, 'scope' => 'raw_rebuild'];
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $capture = $db->provider_captures->findOne([...$query, 'observed_at' => ['$lte' => $feed->receivedAt->toIso8601String()]],
            [...$options, 'sort' => ['observed_at' => -1]]);
        $earlier = $capture !== null;
        $capture ??= $db->provider_captures->findOne($query, [...$options, 'sort' => ['observed_at' => 1]]);
        if ($capture === null) {
            return 'missing_fixture_context';
        }
        $fixtures = $capture['matches'];
        foreach ($fixtures as &$fixture) {
            if (! $earlier || ($fixture['scheduled_start_at'] ?? '') >= $feed->receivedAt->toIso8601String()) {
                $fixture['status'] = 'scheduled_or_unconfirmed';
                $fixture['result'] = $fixture['home_score'] = $fixture['away_score'] = null;
            }
        }
        unset($fixture);
        if ($phase === 'odds') {
            $this->odds->normalize($feed, $fixtures, $tournament, (int) $match[2]);
        } else {
            $this->standings->normalize($feed, $season, $tournament, $fixtures);
        }

        return 'validated';
    }

    private function catalog(array $data): string
    {
        $seasons = $data['seasons'] ?? [];
        $league = $this->league((int) ($seasons[0]['_utid'] ?? 0));
        if ($league === null || $seasons === []) {
            return 'unknown_league';
        }
        $current = 0;
        foreach ($seasons as $season) {
            if ($this->league((int) ($season['_utid'] ?? 0)) !== $league || ! ctype_digit((string) ($season['_id'] ?? ''))) {
                throw new RuntimeException('Archived catalog has conflicting league identities.');
            }
            $current += (int) (($season['current'] ?? false) === true);
        }
        if ($current !== 1) {
            throw new RuntimeException('Archived catalog current season is ambiguous.');
        }

        return 'validated';
    }

    private function league(int $tournament): ?string
    {
        foreach (config('provider.competitions') as $league => $profile) {
            if ($profile['tournament_uid'] === $tournament) {
                return $league;
            }
        }

        return null;
    }
}
