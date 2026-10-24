<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class SeasonDiscovery
{
    public function __construct(private SportradarClient $client) {}

    public function discover(array $profile): array
    {
        $observation = $this->client->statistics('uniquetournament_seasons/s-'.$profile['seed']);
        $seasons = $observation->document['data']['seasons'] ?? [];
        if (! is_array($seasons) || $seasons === []) {
            throw new RuntimeException('Season discovery returned no season list.');
        }
        foreach ($seasons as $season) {
            if ((int) ($season['_utid'] ?? 0) !== $profile['tournament_uid']
                || ! ctype_digit((string) ($season['_id'] ?? ''))) {
                throw new RuntimeException('Season discovery contains invalid or cross-league identities.');
            }
        }
        $current = array_values(array_filter($seasons, fn ($s) => ($s['current'] ?? false) === true));
        if (count($current) !== 1) {
            throw new RuntimeException('Season discovery requires exactly one provider-marked current season.');
        }

        return ['season_id' => (string) $current[0]['_id'], 'observation' => $observation];
    }
}
