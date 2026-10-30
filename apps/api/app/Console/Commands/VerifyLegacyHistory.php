<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\LegacyHistoryVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class VerifyLegacyHistory extends Command
{
    protected $signature = 'provider:verify-legacy-history';

    protected $description = 'Read-only local history integrity report against pinned import plans';

    public function handle(LegacyHistoryVerification $verification): int
    {
        $database = (string) config('database.connections.mongodb.database');
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', $database)) {
            $this->error('History verification requires an isolated timestamped local data mirror.');

            return self::FAILURE;
        }
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $counts = [];
        foreach ($db->local_history_imports->find([], [...$options, 'sort' => ['_id' => 1]]) as $record) {
            $expected = $record['plan']['capture'] ?? [];
            $capture = $db->provider_captures->findOne(['_id' => hash('sha256', (string) $record['_id'])], $options);
            // A verified provider checkpoint may legitimately point to a different capture.
            $checkpoint = $db->season_checkpoints->findOne(['_id' => ($expected['competition'] ?? '?').':'.($expected['season_id'] ?? '?')], ['projection' => ['_id' => 1]]);
            $row = $verification->inspect($record, $capture, $checkpoint !== null);
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            $this->line(json_encode($row, JSON_THROW_ON_ERROR));
        }
        $passed = array_sum($counts) > 0 && ($counts['failed'] ?? 0) === 0;
        $this->line(json_encode(['mode' => 'history_verification', 'database' => $database,
            'records' => array_sum($counts), 'counts' => $counts, 'passed' => $passed,
            'notice' => 'Pinned normalized evidence only; does not certify kickoff timing, champion or financial replay.'], JSON_THROW_ON_ERROR));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
