<?php

namespace App\Infrastructure\Diagnostics;

use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\OddsNormalizer;
use App\Infrastructure\Provider\RawFixtureContext;
use App\Infrastructure\Provider\StandingsNormalizer;
use RuntimeException;

/** Read-only revalidation against present raw_rebuild fixture captures. */
final class RawDocumentValidator
{
    public function __construct(private FixtureNormalizer $fixtures, private OddsNormalizer $odds,
        private StandingsNormalizer $standings, private RawFixtureContext $context, private RawQuoteCoverage $coverage) {}

    public function validate($db, FeedObservation $feed, string $phase, ?callable $details = null): string
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
        $selected = $this->context->find($db->provider_captures, $query, $feed->receivedAt->toIso8601String(),
            $phase === 'odds' ? $this->context->ids($feed) : null);
        if ($selected === null) {
            return 'missing_fixture_context';
        }
        $capture = $selected['capture'];
        $earlier = $selected['earlier'];
        $fixtures = $capture['matches'];
        foreach ($fixtures as &$fixture) {
            if (! $earlier || ($fixture['scheduled_start_at'] ?? '') >= $feed->receivedAt->toIso8601String()) {
                $fixture['status'] = 'scheduled_or_unconfirmed';
                $fixture['result'] = $fixture['home_score'] = $fixture['away_score'] = null;
            }
        }
        unset($fixture);
        if ($phase === 'odds') {
            $prices = $this->odds->normalize($feed, $fixtures, $tournament, (int) $match[2]);
            if ($details !== null) {
                $details(['competition' => $league, 'season_id' => $season, 'matchday' => (int) $match[2],
                    'fixture_capture_id' => $capture['_id'] ?? null, 'fixture_capture_observed_at' => $capture['observed_at'] ?? null,
                    'fixture_context_before_odds' => $earlier, 'joined_fixtures' => count($this->context->ids($feed)),
                    'source_price_coverage' => $this->coverage->inspect($db, $league, $season, $prices),
                    'raw_legacy_acceptance_limited' => ($feed->provenance['origin'] ?? '') === 'legacy_file',
                    'notice' => 'Price coverage only; no repair authorized, market availability or financial eligibility certified.']);
            }
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
