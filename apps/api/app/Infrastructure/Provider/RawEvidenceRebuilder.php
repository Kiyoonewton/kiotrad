<?php

namespace App\Infrastructure\Provider;

use App\Domain\Strategies\SimulationSeasonLinks;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RawEvidenceRebuilder
{
    private array $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function __construct(private ArchivedFeed $archive, private FixtureNormalizer $fixtures,
        private OddsNormalizer $odds, private StandingsNormalizer $standings, private MongoCollectionStore $store) {}

    public function rebuild(string $folder): array
    {
        $database = config('database.connections.mongodb.database');
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', $database)) {
            throw new RuntimeException('Raw rebuild requires an isolated timestamped local database.');
        }
        $db = DB::connection('mongodb')->getDatabase();
        if ($db->provider_captures->countDocuments() > 0) {
            throw new RuntimeException('Rebuild requires an empty destination; existing data is preserved.');
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json.gz')) {
                $record = $this->archive->read($file->getPathname());
                $files[] = ['path' => $file->getPathname(), 'at' => $record['feed']->receivedAt->toIso8601String()];
            }
        }
        usort($files, fn ($a, $b) => [$a['at'], $a['path']] <=> [$b['at'], $b['path']]);
        $counts = ['raw_files' => count($files), 'catalogs' => 0, 'fixtures' => 0, 'odds' => 0, 'standings' => 0, 'invalid_documents' => 0];
        // Catalog and fixture identities must exist before prices/tables can be joined.
        foreach (['catalogs', 'fixtures', 'odds', 'standings'] as $phase) {
            foreach ($files as $file) {
                $record = $this->archive->read($file['path']);
                $feed = $record['feed'];
                if ($record['status'] !== 200 || ! is_array($feed->document['data'] ?? null)) {
                    continue;
                }
                $url = $feed->url;
                try {
                    if ($phase === 'catalogs' && str_contains($url, 'uniquetournament_seasons/')) {
                        $counts[$phase] += $this->catalog($feed);
                    } elseif ($phase === 'fixtures' && preg_match('~stats_season_fixtures2/(\d+)$~', $url, $m)) {
                        $league = $this->league((int) ($feed->document['data']['_utid'] ?? 0));
                        if ($league === null) {
                            continue;
                        }
                        $matches = $this->fixtures->normalize($feed->document['data'], $m[1], config("provider.competitions.{$league}.tournament_uid"));
                        $this->store->save($this->report($league, $m[1], $feed, $matches));
                        $counts[$phase]++;
                    } elseif (($phase === 'odds' && preg_match('~vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$~', $url, $m))
                        || ($phase === 'standings' && preg_match('~stats_season_tables/(\d+)$~', $url, $m))) {
                        $counts[$phase] += $this->joined($phase, $feed, $m[1], isset($m[2]) ? (int) $m[2] : null);
                    }
                } catch (RuntimeException $exception) {
                    $counts['invalid_documents']++;
                }
            }
        }
        if ($counts['fixtures'] === 0 || $counts['catalogs'] === 0) {
            throw new RuntimeException('Raw evidence lacks fixtures or league catalogs; destination not activated.');
        }

        foreach ($db->collector_states->find([], $this->options) as $state) {
            $capture = $db->provider_captures->findOne(['competition' => $state['_id'], 'season_id' => $state['current_season_id']],
                [...$this->options, 'sort' => ['observed_at' => -1]]);
            if ($capture) {
                $db->provider_checkpoints->updateOne(['_id' => $state['_id']], ['$set' => [
                    'capture_id' => $capture['_id'], 'observed_at' => $capture['observed_at'], 'season_id' => $capture['season_id'],
                ]], ['upsert' => true]);
            }
        }

        return $counts;
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

    private function catalog(FeedObservation $feed): int
    {
        $seasons = $feed->document['data']['seasons'] ?? [];
        $league = $this->league((int) ($seasons[0]['_utid'] ?? 0));
        if ($league === null || $seasons === []) {
            return 0;
        }
        $catalog = [];
        $current = [];
        foreach ($seasons as $season) {
            if ($this->league((int) ($season['_utid'] ?? 0)) !== $league || ! ctype_digit((string) ($season['_id'] ?? ''))) {
                throw new RuntimeException('Archived catalog has conflicting league identities.');
            }
            $catalog[] = ['season_id' => (string) $season['_id'], 'start_uts' => $season['start']['uts'] ?? null];
            if (($season['current'] ?? false) === true) {
                $current[] = (string) $season['_id'];
            }
        }
        if (count($current) !== 1) {
            throw new RuntimeException('Archived catalog current season is ambiguous.');
        }
        $db = DB::connection('mongodb')->getDatabase();
        foreach ((new SimulationSeasonLinks)->merge([], $catalog, $feed->receivedAt->toIso8601String()) as $link) {
            $db->provider_catalog_links->updateOne(['_id' => $league.':'.$link['from_season_id'].':'.$link['to_season_id']],
                ['$setOnInsert' => ['competition' => $league, ...$link]], ['upsert' => true]);
        }
        $db->collector_states->updateOne(['_id' => $league], ['$set' => ['current_season_id' => $current[0],
            'collection_observed_at' => $feed->receivedAt->toIso8601String(), 'season_catalog' => $catalog]], ['upsert' => true]);

        return 1;
    }

    private function report(string $league, string $season, FeedObservation $feed, array $matches): array
    {
        return ['competition' => $league, 'season_id' => $season, 'scope' => 'raw_rebuild',
            'observed_at' => $feed->receivedAt->toIso8601String(), 'matches' => $matches, 'odds_snapshots' => [],
            'fixture_count' => count($matches), 'settled_count' => count(array_filter($matches, fn ($m) => $m['status'] === 'settled')),
            'completeness' => (new SeasonCompleteness)->inspect($matches)];
    }

    private function joined(string $phase, FeedObservation $feed, string $season, ?int $day): int
    {
        $db = DB::connection('mongodb')->getDatabase();
        $data = $feed->document['data'];
        $tournament = $phase === 'standings' ? (int) ($data['_utid'] ?? 0) :
            (int) str_replace('vf:tournament:', '', $data['odds'][0]['tournament_id'] ?? '');
        $league = $this->league($tournament);
        if ($league === null) {
            return 0;
        }
        $capture = $db->provider_captures->findOne(['competition' => $league, 'season_id' => $season,
            'scope' => 'raw_rebuild', 'observed_at' => ['$lte' => $feed->receivedAt->toIso8601String()]],
            [...$this->options, 'sort' => ['observed_at' => -1]]);
        $earlier = $capture !== null;
        $capture ??= $db->provider_captures->findOne(['competition' => $league, 'season_id' => $season,
            'scope' => 'raw_rebuild'], [...$this->options, 'sort' => ['observed_at' => 1]]);
        if (! $capture) {
            return 0;
        }
        $matches = $capture['matches'];
        // A later fixture snapshot cannot backdate a result into an earlier odds observation.
        foreach ($matches as &$match) {
            if (! $earlier || ($match['scheduled_start_at'] ?? '') >= $feed->receivedAt->toIso8601String()) {
                $match['status'] = 'scheduled_or_unconfirmed';
                $match['result'] = $match['home_score'] = $match['away_score'] = null;
            }
        }
        unset($match);
        $report = $this->report($league, $season, $feed, $matches);
        $report['scope'] = 'raw_rebuild_'.$phase;
        if ($phase === 'odds') {
            $report['odds_snapshots'] = $this->odds->normalize($feed, $matches, $tournament, $day);
        } else {
            $report['standings'] = $this->standings->normalize($feed, $season, $tournament, $matches);
        }
        $this->store->save($report);

        return 1;
    }
}
