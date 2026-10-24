<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ProviderProbe
{
    public function __construct(
        private SportradarClient $client,
        private SeasonDiscovery $discovery,
        private FixtureNormalizer $fixtures,
        private OddsNormalizer $odds,
    ) {}

    public function run(string $competition, ?int $round = null): array
    {
        $profile = config("provider.competitions.{$competition}");
        if (! is_array($profile) || ! ctype_digit((string) $profile['seed']) || ($round !== null && ($round < 1 || $round > 30))) {
            throw new InvalidArgumentException('Unknown league, invalid bootstrap seed or round outside 1–30.');
        }
        $discovery = $this->discovery->discover($profile);
        $metadata = $discovery['observation'];
        $current = $discovery['season_id'];
        $fixturesFeed = $this->client->statistics('stats_season_fixtures2/'.$current);
        $matches = $this->fixtures->normalize($fixturesFeed->document['data'], $current, $profile['tournament_uid']);
        $now = CarbonImmutable::now('UTC');
        $upcoming = array_values(array_filter($matches, fn ($m) => $m['status'] === 'scheduled_or_unconfirmed'
            && $m['scheduled_start_at'] !== null && CarbonImmutable::parse($m['scheduled_start_at'])->greaterThan($now)));
        usort($upcoming, fn ($a, $b) => strcmp($a['scheduled_start_at'], $b['scheduled_start_at']));
        $round ??= $upcoming[0]['matchday'] ?? null;
        $observations = [$metadata->evidence(), $fixturesFeed->evidence()];
        $prices = [];
        if ($round !== null) {
            $oddsFeed = $this->client->odds($current, $round);
            $prices = $this->odds->normalize($oddsFeed, $matches, $profile['tournament_uid'], $round);
            $observations[] = $oddsFeed->evidence();
        }

        return [
            'competition' => $competition,
            'season_id' => $current,
            'discovery_method' => 'archive_feed_current_flag',
            'discovery_generated_at_seconds' => $metadata->document['_dob'] ?? null,
            'discovery_max_age_seconds' => $metadata->document['_maxage'] ?? null,
            'observed_at' => $fixturesFeed->receivedAt->toIso8601String(),
            'fixture_count' => count($matches),
            'settled_count' => count(array_filter($matches, fn ($m) => $m['status'] === 'settled')),
            'with_scheduled_time' => count(array_filter($matches, fn ($m) => $m['scheduled_start_at'] !== null)),
            'next_scheduled_start_at' => $upcoming[0]['scheduled_start_at'] ?? null,
            'odds_matchday' => $round,
            'priced_matches' => count($prices),
            'verified_pre_match_prices' => count(array_filter($prices, fn ($p) => $p['pre_match_verified'])),
            'matches' => $matches,
            'odds_snapshots' => $prices,
            'observations' => $observations,
        ];
    }
}
