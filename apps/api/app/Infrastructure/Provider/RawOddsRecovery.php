<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RawOddsRecovery
{
    private array $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function __construct(private ArchivedFeed $archive, private OddsNormalizer $normalizer,
        private MongoCollectionStore $store, private RawFixtureContext $context) {}

    public function recover(string $folder, bool $apply, callable $progress): array
    {
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', config('database.connections.mongodb.database'))) {
            throw new RuntimeException('Offline odds recovery requires an isolated timestamped local database.');
        }
        if (! is_dir($folder) || is_link($folder)) {
            throw new RuntimeException('Supply a readable raw evidence directory.');
        }
        $counts = ['files_scanned' => 0, 'odds_files' => 0, 'already_present' => 0, 'recoverable_files' => 0,
            'saved_files' => 0, 'missing_fixture_files' => 0, 'invalid_files' => 0, 'empty_odds_files' => 0];
        $issues = [];
        $seasons = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink() || ! str_ends_with($file->getFilename(), '.json.gz')) {
                continue;
            }
            $counts['files_scanned']++;
            try {
                $record = $this->archive->read($file->getPathname());
                $feed = $record['feed'];
                if ($record['status'] !== 200 || ! preg_match('~vfc_stats_round_odds2/vf:season:(\d+)/(\d+)$~', $feed->url, $m)) {
                    continue;
                }
                $counts['odds_files']++;
                $result = $this->one($feed, $m[1], (int) $m[2], $apply);
                $counts[$result['status']]++;
                if (isset($result['competition'])) {
                    $key = $result['competition'].':'.$m[1];
                    $seasons[$key][$result['status']] = ($seasons[$key][$result['status']] ?? 0) + 1;
                }
            } catch (Throwable $exception) {
                $counts['invalid_files']++;
                // Keep diagnostics bounded; never print response bodies or credentials.
                if (count($issues) < 20) {
                    $issues[] = ['file' => $file->getFilename(), 'error' => class_basename($exception)];
                }
            }
            if ($counts['files_scanned'] % 250 === 0) {
                $progress($counts);
            }
        }

        return ['mode' => $apply ? 'apply' : 'read_only', 'counts' => $counts, 'seasons' => $seasons,
            'issues' => $issues, 'permanent_raw_modified' => false,
            'notice' => 'Stored responses only. Event finish times and historical overlap remain unresolved.'];
    }

    public function one(FeedObservation $feed, string $season, int $day, bool $apply): array
    {
        if (($feed->document['data']['odds'] ?? []) === []) {
            return ['status' => 'empty_odds_files'];
        }
        $tournament = (int) str_replace('vf:tournament:', '', $feed->document['data']['odds'][0]['tournament_id'] ?? '');
        $league = null;
        foreach (config('provider.competitions') as $id => $profile) {
            if ($profile['tournament_uid'] === $tournament) {
                $league = $id;
            }
        }
        if ($league === null || $day < 1 || $day > 30) {
            throw new RuntimeException('Unknown league or round in archived odds.');
        }
        $db = DB::connection('mongodb')->getDatabase();
        $at = $feed->receivedAt->toIso8601String();
        $query = ['competition' => $league, 'season_id' => $season];
        $selected = $this->context->find($db->provider_captures, $query, $at, $this->context->ids($feed));
        if ($selected === null) {
            return ['status' => 'missing_fixture_files', 'competition' => $league];
        }
        $capture = $selected['capture'];
        $earlier = $selected['earlier'];
        $matches = $capture['matches'] ?? [];
        foreach ($matches as &$match) {
            if (! $earlier || ($match['scheduled_start_at'] ?? '') >= $at) {
                $match['status'] = 'scheduled_or_unconfirmed';
                $match['result'] = $match['home_score'] = $match['away_score'] = null;
            }
        }
        unset($match);
        $prices = $this->normalizer->normalize($feed, $matches, $tournament, $day);
        if (! $earlier) {
            foreach ($prices as &$price) {
                $price['pre_match_verified'] = false;
            }
            unset($price);
        }
        if (($feed->provenance['origin'] ?? '') === 'legacy_file') {
            $prices = array_values(array_filter($prices, fn ($p) => in_array($p['provider_match_id'], $feed->provenance['accepted_match_ids'] ?? [], true)));
        }
        if ($prices === []) {
            return ['status' => 'empty_odds_files', 'competition' => $league];
        }
        $conditions = array_map(fn ($price) => ['$elemMatch' => [
            'provider_match_id' => $price['provider_match_id'], 'captured_at' => $price['captured_at'],
            'prices.H' => $price['prices']['H'], 'prices.D' => $price['prices']['D'], 'prices.A' => $price['prices']['A'],
        ]], $prices);
        $known = $db->provider_captures->findOne([...$query, 'odds_snapshots' => ['$all' => $conditions]],
            [...$this->options, 'projection' => ['_id' => 1]]);
        if ($known !== null) {
            if ($apply) {
                $db->season_checkpoints->updateOne(['_id' => $league.':'.$season], ['$inc' => ['odds_evidence_revision' => 1]]);
            }

            return ['status' => 'already_present', 'competition' => $league];
        }
        if (! $apply) {
            return ['status' => 'recoverable_files', 'competition' => $league];
        }
        $report = ['competition' => $league, 'season_id' => $season, 'scope' => 'offline_raw_odds_recovery',
            'observed_at' => $at, 'matches' => $matches, 'odds_snapshots' => $prices,
            'fixture_count' => count($matches), 'settled_count' => count(array_filter($matches, fn ($m) => ($m['status'] ?? '') === 'settled')),
            'odds_matchday' => $day];
        $this->store->save($report);
        // Old evidence can be imported without changing the latest observation pointer.
        // Invalidate affected derived views even when the checkpoint's capture_id stays unchanged.
        $db->season_checkpoints->updateOne(['_id' => $league.':'.$season],
            ['$inc' => ['odds_evidence_revision' => 1]]);

        return ['status' => 'saved_files', 'competition' => $league];
    }
}
