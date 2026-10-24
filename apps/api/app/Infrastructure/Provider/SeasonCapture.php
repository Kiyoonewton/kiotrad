<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Throwable;

final class SeasonCapture
{
    public function __construct(
        private SportradarClient $client,
        private FixtureNormalizer $fixtures,
        private OddsNormalizer $odds,
        private SeasonCompleteness $completeness,
        private AvailabilityEvidence $evidence,
    ) {}

    public function run(string $competition, string $season, string $scope, array $captured, int $limit = 2): array
    {
        $profile = config("provider.competitions.{$competition}");
        $feed = $this->client->statistics('stats_season_fixtures2/'.$season);
        $matches = $this->fixtures->normalize($feed->document['data'], $season, $profile['tournament_uid']);
        $now = CarbonImmutable::now('UTC');
        $future = array_values(array_filter($matches, fn ($m) => $m['status'] === 'scheduled_or_unconfirmed'
            && $m['scheduled_start_at'] !== null && CarbonImmutable::parse($m['scheduled_start_at'])->greaterThan($now)));
        usort($future, fn ($a, $b) => strcmp($a['scheduled_start_at'], $b['scheduled_start_at']));
        $rounds = array_slice(array_values(array_unique(array_column($future, 'matchday'))), 0, $limit);
        $prices = [];
        $coverage = [];
        $keys = [];
        $unknown = false;
        foreach ($rounds as $round) {
            $key = $season.':'.$round;
            if (in_array($key, $captured, true)) {
                $coverage[] = ['matchday' => $round, 'status' => 'previously_captured'];

                continue;
            }
            $oddsFeed = null;
            try {
                $oddsFeed = $this->client->odds($season, $round);
                $rows = $this->odds->normalize($oddsFeed, $matches, $profile['tournament_uid'], $round);
                $prices = [...$prices, ...$rows];
                $count = count(array_filter($rows, fn ($p) => $p['pre_match_verified']));
                if ($count === 8) {
                    $keys[] = $key;
                } else {
                    $unknown = true;
                }
                $coverage[] = ['matchday' => $round, 'status' => $count === 8 ? 'captured' : 'availability_unknown', 'pre_match_count' => $count];
            } catch (Throwable $exception) {
                if ($exception instanceof ProviderFeedException) {
                    $oddsFeed = $exception->observation;
                }
                $unknown = true;
                $coverage[] = ['matchday' => $round, 'status' => 'availability_unknown', 'pre_match_count' => 0];
                report($exception);
            }
            if (end($coverage)['status'] === 'availability_unknown') {
                try {
                    $this->evidence->record($competition, $season, $round, $oddsFeed);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
        $due = [];
        foreach ($matches as $match) {
            if ($match['status'] !== 'settled' && $match['scheduled_start_at'] === null) {
                $due[] = $now->addSeconds(300);
            }
            if ($match['status'] !== 'settled' && $match['scheduled_start_at'] !== null) {
                $expected = CarbonImmutable::parse($match['scheduled_start_at'])->addSeconds(config('provider.result_check_delay_seconds'));
                $due[] = $expected->greaterThan($now) ? $expected : $now->addSeconds(30);
            }
        }
        usort($due, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return [
            'competition' => $competition, 'season_id' => $season, 'scope' => $scope,
            'observed_at' => $feed->receivedAt->toIso8601String(),
            'fixture_observation' => ['requested_at' => $feed->requestedAt->toIso8601String(),
                'received_at' => $feed->receivedAt->toIso8601String(), 'feed_generated_at_seconds' => $feed->document['_dob'] ?? null],
            'fixture_count' => count($matches),
            'settled_count' => count(array_filter($matches, fn ($m) => $m['status'] === 'settled')),
            'matches' => $matches, 'odds_snapshots' => $prices,
            'odds_matchday' => $rounds[0] ?? null, 'odds_matchdays' => $rounds,
            'odds_coverage' => $coverage, 'captured_round_keys' => $keys,
            'market_observation' => $unknown ? 'availability_unknown' : ($rounds === [] ? 'not_requested' : ($prices === [] ? 'previous_prices_captured' : 'feed_prices_observed')),
            'verified_pre_match_prices' => count(array_filter($prices, fn ($p) => $p['pre_match_verified'])),
            'next_scheduled_start_at' => $future[0]['scheduled_start_at'] ?? null,
            'result_due_at' => isset($due[0]) ? $due[0]->toIso8601String() : null,
            'completeness' => $this->completeness->inspect($matches),
        ];
    }
}
