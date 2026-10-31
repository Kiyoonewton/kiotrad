<?php

namespace App\Console\Commands;

use App\Infrastructure\History\ProductionEvidenceIndex;
use App\Infrastructure\History\ProductionHistoryAssessment;
use App\Infrastructure\History\ProductionHistoryDescriptor;
use App\Infrastructure\History\ProductionHistoryProof;
use App\Infrastructure\History\ProductionHistorySources;
use App\Infrastructure\History\ProductionHistoryStore;
use App\Infrastructure\Provider\LegacyHistoryArchive;
use App\Infrastructure\Provider\SnapshotLease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ImportReviewedHistory extends Command
{
    protected $signature = 'provider:import-reviewed-history {folder} {plan} {backup} {--apply}';

    protected $description = 'Production history preflight; explicit --apply appends reviewed raw-first evidence';

    public function handle(ProductionHistoryProof $proofReader, ProductionHistorySources $reader,
        ProductionEvidenceIndex $index, ProductionHistoryAssessment $assessment, ProductionHistoryStore $store,
        LegacyHistoryArchive $archive, ProductionHistoryDescriptor $descriptor): int
    {
        if (config('kiotrad.local_data_mirror') || config('database.connections.mongodb.database') !== 'kiotrad') {
            $this->error('Reviewed production import requires the production kiotrad database.');

            return self::FAILURE;
        }
        $lock = null;
        try {
            $folder = $this->argument('folder');
            $proof = $proofReader->read($folder, $this->argument('plan'), $this->argument('backup'));
            if ($this->option('apply')) {
                $lock = Cache::store('redis')->lock('reviewed-production-history:kiotrad', 7200);
                if (! $lock->get()) {
                    throw new RuntimeException('Another production history import owns the lease.');
                }
                SnapshotLease::protect($lock);
            }
            $db = DB::connection('mongodb')->getDatabase();
            $packageKey = ProductionHistoryStore::VERSION.':'.$proof['manifest_sha256'];
            $packageRecord = $store->record($db, $packageKey);
            if ($packageRecord !== null && $packageRecord['proof'] !== $proof) {
                throw new RuntimeException('Package was previously pinned to a different review or backup.');
            }
            $at = $packageRecord['started_at'] ?? now('UTC')->toIso8601String();
            $sources = $reader->groups($folder, fn ($row) => $this->emit($row));
            $this->emit(['phase' => 'indexing_production_evidence']);
            $index->load($db, $sources['ids']);
            $totals = ['new_matches' => 0, 'new_prices' => 0];
            $digests = [];
            foreach ($sources['groups'] as $season => $files) {
                $incoming = array_map(fn ($entry) => $reader->parse($entry), $files);
                $ids = array_values(array_unique(array_column(array_merge(...array_column($incoming, 'matches')), 'provider_match_id')));
                $key = $packageKey.':'.$season;
                $record = $store->record($db, $key);
                $current = $assessment->build($incoming, $index->captures($ids), $at, $proof);
                if ($record !== null) {
                    $capture = $db->provider_captures->findOne(['_id' => hash('sha256', $key)]);
                    if ($record['plan']['new_matches'] + $record['plan']['new_prices'] === 0) {
                        $store->verifyComplete($db, $key, $record);
                    } elseif ($capture !== null || $record['status'] === 'complete') {
                        $store->verifyCapture($db, $key, $record['plan']);
                        if ($record['status'] === 'complete') {
                            $store->verifyComplete($db, $key, $record);
                        }
                    } elseif ($store->digest($current) !== $store->digest($record['plan'])) {
                        throw new RuntimeException('Prepared season changed before capture creation: '.$season);
                    }
                    $current = $record['plan'];
                }
                if (($proof['season_actions'][$season] ?? null) !== $current['action_sha256']) {
                    throw new RuntimeException('Fixture/price additions differ from reviewed season: '.$season);
                }
                foreach (array_keys($totals) as $metric) {
                    $totals[$metric] += $current[$metric];
                }
                $digests[$season] = $store->digest($current);
                $this->emit(['phase' => 'preflight', 'season' => $season, 'done' => count($digests), 'total' => count($sources['groups']),
                    'new_matches' => $current['new_matches'], 'new_prices' => $current['new_prices']]);
            }
            if ($totals !== ['new_matches' => $proof['expected_matches'], 'new_prices' => $proof['expected_prices']]) {
                throw new RuntimeException('Current additions differ from reviewed totals; generate and review a fresh plan.');
            }
            unset($index);
            if (! $this->option('apply')) {
                $this->emit(['mode' => 'production_history_preflight', 'passed' => true, 'applied' => false, 'totals' => $totals]);

                return self::SUCCESS;
            }
            $this->lease($lock);
            $db->production_history_imports->updateOne(['_id' => $packageKey], ['$setOnInsert' => [
                'kind' => 'package', 'status' => 'prepared', 'proof' => $proof, 'started_at' => $at,
            ]], ['upsert' => true]);
            $root = storage_path('app/private/provider-evidence/raw/'.substr($at, 0, 4).'/'.substr($at, 5, 2).'/'.substr($at, 8, 2));
            if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
                throw new RuntimeException('Permanent raw archive is unavailable.');
            }
            // Apply-only supporting index: no full capture scan for every live season recheck.
            $db->provider_captures->createIndex(['competition' => 1, 'season_id' => 1], ['name' => 'history_competition_season']);
            foreach ($sources['groups'] as $season => $files) {
                $this->lease($lock);
                $key = $packageKey.':'.$season;
                $record = $store->record($db, $key);
                if (($record['status'] ?? '') === 'complete') {
                    $store->verifyComplete($db, $key, $record);

                    continue;
                }
                $incoming = array_map(fn ($entry) => $reader->parse($entry), $files);
                $capture = $db->provider_captures->findOne(['_id' => hash('sha256', $key)]);
                if ($capture !== null && $record !== null) {
                    $store->verifyCapture($db, $key, $record['plan']);
                    $plan = $record['plan'];
                } else {
                    $live = (new ProductionEvidenceIndex)->live($db, $incoming[0]['competition'], $incoming[0]['season_id']);
                    $plan = $assessment->build($incoming, $live, $at, $proof);
                    if ($store->digest($plan) !== $digests[$season]) {
                        throw new RuntimeException('Production evidence changed after preflight: '.$season);
                    }
                }
                $store->prepare($db, $key, $plan, $proof);
                $this->lease($lock);
                $archive->preserve($root, $files, $at);
                $this->lease($lock);
                $store->materialize($db, $key, $plan);
                $db->production_history_imports->updateOne(['_id' => $key], ['$set' => ['status' => 'complete']]);
                $this->emit(['phase' => 'applied', 'season' => $season]);
            }
            $this->lease($lock);
            $hash = $descriptor->publish($root, $folder, $at);
            $db->production_history_imports->updateOne(['_id' => $packageKey], ['$set' => ['status' => 'complete', 'descriptor_sha256' => $hash]]);
            $this->emit(['mode' => 'production_history_apply', 'complete' => true, 'totals' => $totals,
                'estimated_dates_applied' => false, 'settings_changed' => false]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('History import stopped: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($lock !== null) {
                SnapshotLease::release();
            }
        }
    }

    private function lease($lock): void
    {
        if (! $lock->isOwnedByCurrentProcess()) {
            throw new RuntimeException('Production import lease expired; resume the same pinned package.');
        }
    }

    private function emit(array $row): void
    {
        $this->line(json_encode($row, JSON_THROW_ON_ERROR));
    }
}
