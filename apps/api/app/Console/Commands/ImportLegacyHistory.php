<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\LegacyHistoryArchive;
use App\Infrastructure\Provider\LegacyHistoryComparison;
use App\Infrastructure\Provider\LegacyHistoryPackage;
use App\Infrastructure\Provider\LegacyHistoryParser;
use App\Infrastructure\Provider\LegacyHistoryPlan;
use App\Infrastructure\Provider\LegacyHistoryWriter;
use App\Infrastructure\Provider\SnapshotLease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ImportLegacyHistory extends Command
{
    protected $signature = 'provider:import-legacy-history {folder} {--apply} {--archive-root=}';

    protected $description = 'Local-only raw-first history import; defaults to read-only database audit';

    public function handle(LegacyHistoryPackage $package, LegacyHistoryParser $parser,
        LegacyHistoryComparison $comparison, LegacyHistoryPlan $planner,
        LegacyHistoryArchive $archive, LegacyHistoryWriter $writer): int
    {
        $database = (string) config('database.connections.mongodb.database');
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', $database)) {
            $this->error('History import requires an isolated timestamped local data mirror.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            return $this->call('provider:audit-legacy-history', ['folder' => $this->argument('folder')]);
        }
        $root = $this->option('archive-root');
        if (! is_string($root) || ! is_dir($root) || is_link($root) || ! is_writable($root)) {
            $this->error('Supply --archive-root pointing to a persistent writable host mount.');

            return self::FAILURE;
        }
        $lock = Cache::store('redis')->lock('legacy-history-import:'.$database, 7200);
        if (! $lock->get()) {
            $this->error('Another history import owns the local database lease.');

            return self::FAILURE;
        }
        SnapshotLease::protect($lock);
        try {
            $entries = $package->entries($this->argument('folder'));
            $groups = [];
            $identities = [];
            // Entire package is parsed before writes, including cross-season source-ID checks.
            foreach ($entries as $entry) {
                $source = $this->parse($entry, $parser);
                foreach ($source['matches'] as $match) {
                    $id = $match['provider_match_id'];
                    $fingerprint = hash('sha256', json_encode([$source['competition'], $match['season_id'],
                        $match['matchday'], $match['home_team_uid'], $match['away_team_uid']], JSON_THROW_ON_ERROR));
                    if (isset($identities[$id]) && $identities[$id] !== $fingerprint) {
                        throw new RuntimeException('Conflicting source match identity across package seasons.');
                    }
                    $identities[$id] = $fingerprint;
                }
                $groups[$source['competition'].':'.$source['season_id']][] = $entry;
            }
            unset($identities);
            ksort($groups);
            $db = DB::connection('mongodb')->getDatabase();
            $counts = [];
            $position = 0;
            $totals = ['new_matches' => 0, 'new_prices' => 0];
            $failed = false;
            foreach ($groups as $season => $files) {
                if (! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('History import lease expired.');
                }
                $sources = array_map(fn ($entry) => $this->parse($entry, $parser), $files);
                $ids = array_values(array_unique(array_merge(...array_map(fn ($s) => array_column($s['matches'], 'provider_match_id'), $sources))));
                $hashes = array_column($files, 'sha256');
                sort($hashes);
                $key = LegacyHistoryPlan::VERSION.':'.$season.':'.hash('sha256', implode(':', $hashes));
                $at = now('UTC')->toIso8601String();
                // Durable originals precede the ledger and normalized database writes.
                $archive->preserve($root, $files, $at);
                $record = $db->local_history_imports->findOne(['_id' => $key]);
                if (($record['status'] ?? '') === 'complete') {
                    $result = ['status' => 'already_imported'];
                } else {
                    $captures = $db->provider_captures->find(['$or' => [
                        ['competition' => $sources[0]['competition'], 'season_id' => $sources[0]['season_id']],
                        ['matches.provider_match_id' => ['$in' => $ids]],
                    ]], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                        'projection' => ['competition' => 1, 'matches' => 1, 'odds_snapshots' => 1], 'sort' => ['observed_at' => 1], 'batchSize' => 5]);
                    $existing = [];
                    $stream = (function () use ($captures, &$existing) {
                        foreach ($captures as $capture) {
                            foreach ($capture['matches'] ?? [] as $match) {
                                $id = $match['provider_match_id'];
                                $rank = fn ($m) => (int) ! empty($m['scheduled_start_at']) * 4
                                    + (int) ! empty($m['result']) * 2 + (int) isset($m['home_score'], $m['away_score']);
                                if (! isset($existing[$id]) || $rank($match) >= $rank($existing[$id])) {
                                    $existing[$id] = $match;
                                }
                            }
                            yield $capture;
                        }
                    })();
                    $rows = $comparison->compare($sources, $stream);
                    if (array_filter($rows, fn ($row) => $row['conflicts'] !== [])) {
                        $result = ['status' => 'conflict_raw_preserved'];
                        $failed = true;
                    } else {
                        if (! $lock->isOwnedByCurrentProcess()) {
                            throw new RuntimeException('History import lease expired before commit.');
                        }
                        $plan = $planner->build($sources, $rows, $at, $existing);
                        $result = $writer->apply($db, $key, $plan);
                    }
                }
                foreach (array_keys($totals) as $metric) {
                    $totals[$metric] += $result[$metric] ?? 0;
                }
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                $this->line(json_encode(['season' => $season, 'progress' => ++$position.'/'.count($groups), ...$result], JSON_THROW_ON_ERROR));
            }
            $this->line(json_encode(['mode' => 'local_apply', 'database' => $database, 'seasons' => count($groups),
                'counts' => $counts, 'totals' => $totals, 'timing_estimated' => false], JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('History import stopped; originals and completed seasons are retained. Rerun the same package to resume.');

            return self::FAILURE;
        } finally {
            SnapshotLease::release();
        }
    }

    private function parse(array $entry, LegacyHistoryParser $parser): array
    {
        $body = file_get_contents($entry['path']);
        if (! hash_equals($entry['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('History package changed after verification.');
        }
        $source = $parser->parse($body, $entry['source_filename'] ?? $entry['file']);
        foreach (['competition', 'season_id', 'matchday'] as $field) {
            if ((string) $source[$field] !== (string) ($entry[$field] ?? '')) {
                throw new RuntimeException('History source differs from package manifest.');
            }
        }

        return $source;
    }
}
