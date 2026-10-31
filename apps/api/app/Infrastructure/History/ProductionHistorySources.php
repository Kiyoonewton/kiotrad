<?php

namespace App\Infrastructure\History;

use App\Infrastructure\Provider\LegacyHistoryPackage;
use App\Infrastructure\Provider\LegacyHistoryParser;
use RuntimeException;

final class ProductionHistorySources
{
    public function __construct(private LegacyHistoryPackage $package, private LegacyHistoryParser $parser) {}

    public function groups(string $folder, callable $progress): array
    {
        $entries = $this->package->entries($folder);
        $groups = $identities = [];
        foreach ($entries as $i => $entry) {
            $source = $this->parse($entry);
            foreach ($source['matches'] as $match) {
                $id = $match['provider_match_id'];
                $identity = json_encode([$source['competition'], $source['season_id'], $match['matchday'], $match['home_team_uid'], $match['away_team_uid']], JSON_THROW_ON_ERROR);
                if (isset($identities[$id]) && $identities[$id] !== $identity) {
                    throw new RuntimeException('Conflicting source match identity across package seasons.');
                }
                $identities[$id] = $identity;
            }
            $groups[$source['competition'].':'.$source['season_id']][] = $entry;
            if (($i + 1) % 250 === 0 || $i + 1 === count($entries)) {
                $progress(['phase' => 'package_validation', 'done' => $i + 1, 'total' => count($entries)]);
            }
        }
        ksort($groups);

        return ['groups' => $groups, 'ids' => array_keys($identities)];
    }

    public function parse(array $entry): array
    {
        $body = file_get_contents($entry['path']);
        if (! hash_equals($entry['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Source package changed after validation.');
        }
        $source = $this->parser->parse($body, $entry['source_filename'] ?? $entry['file']);
        foreach (['competition', 'season_id', 'matchday'] as $field) {
            if ((string) $source[$field] !== (string) $entry[$field]) {
                throw new RuntimeException('Raw source identity differs from package manifest.');
            }
        }

        return $source;
    }
}
