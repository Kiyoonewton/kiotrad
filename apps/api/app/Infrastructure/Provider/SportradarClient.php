<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SportradarClient
{
    public function statistics(string $route): FeedObservation
    {
        return $this->request(config('provider.statistics_base').$route);
    }

    public function odds(string $season, int $round): FeedObservation
    {
        return $this->request(config('provider.odds_base')."vfc_stats_round_odds2/vf:season:{$season}/{$round}");
    }

    private function request(string $url): FeedObservation
    {
        $started = CarbonImmutable::now('UTC');
        // A diagnostic makes one request per endpoint. A later worker owns retry/backoff.
        $response = Http::acceptJson()->connectTimeout(10)->timeout(30)->get($url)->throw();
        $received = CarbonImmutable::now('UTC');
        $payload = $response->json();
        $document = is_array($payload) ? ($payload['doc'][0] ?? null) : null;
        if (! is_array($document) || ($document['event'] ?? null) === 'exception'
            || ! is_array($document['data'] ?? null)) {
            throw new RuntimeException('Provider returned no usable feed document.');
        }

        return new FeedObservation($url, $started, $received, $document, $payload);
    }
}
