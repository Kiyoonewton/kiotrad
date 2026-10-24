<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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
        $response = Http::acceptJson()->connectTimeout(10)->timeout(30)->get($url);
        $received = CarbonImmutable::now('UTC');
        app(RawFeedArchive::class)->record($url, $started->toIso8601String(), $received->toIso8601String(), $response->status(), $response->body());
        $payload = $response->json();
        $document = is_array($payload) ? ($payload['doc'][0] ?? null) : null;
        $raw = is_array($payload) ? $payload : ['raw_body' => substr($response->body(), 0, 1048576)];
        $observedDocument = is_array($document) ? $document : [];
        $observedDocument['_http_status'] = $response->status();
        $observation = new FeedObservation($url, $started, $received, $observedDocument, $raw);
        if (! $response->successful() || ! is_array($document) || ($document['event'] ?? null) === 'exception'
            || ! is_array($document['data'] ?? null)) {
            throw new ProviderFeedException($observation);
        }

        return $observation;
    }
}
