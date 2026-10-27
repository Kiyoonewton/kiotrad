<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\LegacyHistoryComparison;
use App\Infrastructure\Provider\LegacyHistoryPackage;
use App\Infrastructure\Provider\LegacyHistoryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class AuditLegacyHistory extends Command
{
    protected $signature = 'provider:audit-legacy-history {folder}';

    protected $description = 'Local-only read-only package comparison against current MongoDB; no apply mode';

    public function handle(LegacyHistoryPackage $package, LegacyHistoryParser $parser, LegacyHistoryComparison $comparison): int
    {
        $database = (string) config('database.connections.mongodb.database');
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', $database)) {
            $this->error('This audit requires an isolated local data mirror.');

            return self::FAILURE;
        }
        try {
            $entries = $package->entries($this->argument('folder'));
            $groups = [];
            foreach ($entries as $entry) {
                $groups[$entry['competition'].':'.$entry['season_id']][] = $entry;
            }
            ksort($groups);
            $counts = [];
            $failed = false;
            $db = DB::connection('mongodb')->getDatabase();
            foreach ($groups as $identity => $files) {
                try {
                    $sources = [];
                    $ids = [];
                    foreach ($files as $file) {
                        $body = file_get_contents($file['path']);
                        if (! hash_equals($file['sha256'], hash('sha256', $body))) {
                            throw new RuntimeException('Package changed after verification.');
                        }
                        $source = $parser->parse($body, $file['source_filename'] ?? $file['file']);
                        foreach (['competition', 'season_id', 'matchday'] as $field) {
                            if ((string) $source[$field] !== (string) ($file[$field] ?? '')) {
                                throw new RuntimeException('Source identity differs from manifest.');
                            }
                        }
                        $sources[] = $source;
                        $ids = [...$ids, ...array_column($source['matches'], 'provider_match_id')];
                    }
                    $captures = $db->provider_captures->find([
                        '$or' => [['competition' => $sources[0]['competition'], 'season_id' => $sources[0]['season_id']],
                            ['matches.provider_match_id' => ['$in' => array_values(array_unique($ids))]]],
                    ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                        'projection' => ['competition' => 1, 'matches' => 1, 'odds_snapshots' => 1], 'batchSize' => 5]);
                    $rows = $comparison->compare($sources, $captures);
                    $conflicts = array_values(array_filter($rows, fn ($row) => $row['conflicts'] !== []));
                    $failed = $failed || $conflicts !== [];
                    $summary = ['season' => $identity, 'files' => count($files),
                        'unique_match_ids' => count(array_unique($ids)), 'conflicting_rows' => count($conflicts),
                        'fixture_statuses' => array_count_values(array_column($rows, 'fixture_status')),
                        'price_statuses' => array_count_values(array_column($rows, 'price_status')),
                        'timing_statuses' => array_count_values(array_column($rows, 'timing_status')),
                        'rows' => $rows, 'interruption_cause' => 'not_established'];
                    $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    $counts[$conflicts === [] ? 'assessed' : 'conflicting'] = ($counts[$conflicts === [] ? 'assessed' : 'conflicting'] ?? 0) + 1;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->line(json_encode(['season' => $identity, 'status' => 'rejected',
                        'reason' => 'Package parsing or database comparison failed; inspect local logs.'], JSON_THROW_ON_ERROR));
                    $counts['rejected'] = ($counts['rejected'] ?? 0) + 1;
                    $failed = true;
                }
            }
            $this->line(json_encode(['mode' => 'read_only', 'database' => $database,
                'seasons' => count($groups), 'counts' => $counts, 'applied' => false], JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('History audit failed before completion; no data imported. Inspect local logs.');

            return self::FAILURE;
        }
    }
}
