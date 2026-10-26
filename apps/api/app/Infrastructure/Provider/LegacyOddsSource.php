<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use RuntimeException;

final class LegacyOddsSource
{
    public function decode(string $body, string $filename, string $importedAt): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! preg_match('~^vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$~D', $payload['queryUrl'] ?? '', $m)) {
            throw new RuntimeException('Not a supported legacy season/round odds response.');
        }
        $document = $payload['doc'][0] ?? null;
        if (! is_array($document) || ! is_array($document['data']['odds'] ?? null) || $document['data']['odds'] === []) {
            throw new RuntimeException('Legacy response has no odds events.');
        }
        $tournament = $document['data']['odds'][0]['tournament_id'] ?? '';
        $league = null;
        foreach (config('provider.competitions') as $id => $profile) {
            if ($tournament === 'vf:tournament:'.$profile['tournament_uid']) {
                $league = $id;
            }
        }
        if ($league === null || (int) $m[2] < 1 || (int) $m[2] > 30) {
            throw new RuntimeException('Unrecognized league or matchday.');
        }
        $provenance = ['origin' => 'legacy_file', 'source_filename' => basename($filename),
            'source_sha256' => hash('sha256', $body), 'original_requested_at' => null,
            'original_received_at' => null, 'imported_at' => $importedAt,
            'timestamp_basis' => 'import_time_original_retrieval_unknown', 'url_basis' => 'reconstructed_from_query_route'];
        $time = CarbonImmutable::parse($importedAt);
        $feed = new FeedObservation(config('provider.odds_base').$payload['queryUrl'], $time, $time, $document, $payload, $provenance);

        return ['competition' => $league, 'season_id' => $m[1], 'matchday' => (int) $m[2], 'feed' => $feed,
            'provenance' => $provenance, 'body' => $body];
    }

    public function envelope(array $source): array
    {
        return ['schema_version' => 2, 'url' => $source['feed']->url, 'requested_at' => null, 'received_at' => null,
            'imported_at' => $source['provenance']['imported_at'], 'provenance' => $source['provenance'],
            'http_status' => null, 'body_sha256' => hash('sha256', $source['body']), 'body_base64' => base64_encode($source['body'])];
    }
}
