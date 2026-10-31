<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

/** Local disposable index; raw envelopes remain the source of truth. */
final class RawRebuildIndex
{
    public function version(): string
    {
        $paths = [__FILE__, app_path('Infrastructure/Provider/RawEvidenceRebuilder.php')];
        foreach (['RawFixtureContext', 'ArchivedFeed', 'FixtureNormalizer', 'OddsNormalizer', 'StandingsNormalizer', 'MongoCollectionStore', 'SeasonCompleteness'] as $name) {
            $paths[] = app_path('Infrastructure/Provider/'.$name.'.php');
        }
        $paths[] = app_path('Domain/Strategies/SimulationSeasonLinks.php');

        return hash('sha256', json_encode([array_map(fn ($p) => hash_file('sha256', $p), $paths), config('provider.competitions')]));
    }

    public function prepare(string $folder, $db, ArchivedFeed $archive, ?callable $progress): array
    {
        $state = $db->local_raw_rebuild->findOne(['_id' => 'state']);
        $incremental = $state !== null;
        if ($incremental && ($state['version'] !== $this->version() || ! $state['complete'])) {
            throw new RuntimeException('Local parser changed or seed incomplete; use --full-rebuild.');
        }
        $known = [];
        foreach ($db->local_raw_files->find([], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]) as $row) {
            $known[$row['_id']] = $row;
        }
        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink() && str_ends_with($file->getFilename(), '.json.gz')) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        $rows = $changed = [];
        foreach ($paths as $i => $path) {
            $name = substr($path, strlen(rtrim($folder, '/')) + 1);
            $hash = hash_file('sha256', $path);
            $old = $known[$name] ?? null;
            if ($old && $old['hash'] === $hash) {
                $row = $old;
            } else {
                $record = $archive->read($path);
                $row = ['_id' => $name, 'hash' => $hash, 'at' => $record['feed']->receivedAt->toIso8601String(),
                    ...$this->identify($record['feed']->url)];
                $row['history_source_sha256'] = $record['body_sha256'];
                $provenance = $record['feed']->provenance;
                if (($provenance['origin'] ?? '') === 'production_history_package') {
                    $row['history_package_sha256'] = $provenance['package_manifest_sha256'];
                } elseif (($provenance['origin'] ?? '') === 'legacy_file') {
                    $row['history_source_sha256'] = $provenance['source_sha256'];
                }
                $changed[$name] = true;
            }
            $rows[$name] = [...$row, 'path' => $path];
            if ($progress && (($i + 1) % 250 === 0 || $i + 1 === count($paths))) {
                $progress('index', $i + 1, count($paths));
            }
        }
        if (array_diff_key($known, $rows)) {
            throw new RuntimeException('Previously processed raw evidence is absent; use --full-rebuild after checking the source.');
        }
        $affected = [];
        foreach (array_keys($changed) as $name) {
            if ($rows[$name]['season'] !== null) {
                $affected[$rows[$name]['season']] = true;
            }
        }
        // Replaying a touched season also rejoins older unpriced/deferred observations.
        $selected = array_filter($rows, fn ($r) => $r['phase'] === 'catalogs'
            ? isset($changed[$r['_id']]) : isset($affected[$r['season'] ?? '']));
        usort($selected, fn ($a, $b) => [$a['at'], $a['_id']] <=> [$b['at'], $b['_id']]);

        return compact('rows', 'selected', 'affected', 'incremental', 'changed') + ['new_files' => count($changed)];
    }

    private function identify(string $url): array
    {
        if (str_contains($url, 'uniquetournament_seasons/')) {
            return ['phase' => 'catalogs', 'season' => null];
        }
        foreach (['fixtures' => '~stats_season_fixtures2/(\d+)$~', 'odds' => '~vfc_stats_round_odds2/vf:season:(\d+)/\d+$~',
            'standings' => '~stats_season_tables/(\d+)$~'] as $phase => $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return ['phase' => $phase, 'season' => $m[1]];
            }
        }

        return ['phase' => 'ignored', 'season' => null];
    }

    public function commit($db, array $plan): void
    {
        foreach ($plan['rows'] as $row) {
            if (! isset($plan['changed'][$row['_id']])) {
                continue;
            }
            unset($row['path']);
            $db->local_raw_files->replaceOne(['_id' => $row['_id']], $row, ['upsert' => true]);
        }
        $db->local_raw_rebuild->replaceOne(['_id' => 'state'], ['_id' => 'state', 'version' => $this->version(), 'complete' => true], ['upsert' => true]);
    }
}
