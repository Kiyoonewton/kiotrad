<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\ProviderProbe;
use Illuminate\Console\Command;
use Throwable;

class ProbeProvider extends Command
{
    protected $signature = 'provider:probe {competition? : One league ID; omit for all three} {--round= : Inspect a specific round, 1–30}';

    protected $description = 'Read provider fixtures, scheduled times and odds into private diagnostic snapshots; no database writes';

    public function handle(ProviderProbe $probe): int
    {
        $profiles = config('provider.competitions');
        $competition = $this->argument('competition');
        $round = $this->option('round');
        if (($competition !== null && ! isset($profiles[$competition]))
            || ($round !== null && (! ctype_digit($round) || (int) $round < 1 || (int) $round > 30))) {
            $this->error('Use a configured league ID and a round between 1 and 30.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/provider-probes');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('Cannot create private diagnostics directory.');

            return self::FAILURE;
        }
        $failures = 0;
        $rows = [];
        foreach ($competition === null ? array_keys($profiles) : [$competition] as $league) {
            try {
                $report = $probe->run($league, $round === null ? null : (int) $round);
                // Unique file opened exclusively; observations never overwrite an earlier capture.
                $path = $directory.'/'.now('UTC')->format('Ymd-His-u').'-'.$league.'-'.bin2hex(random_bytes(4)).'.json';
                $file = fopen($path, 'x');
                if ($file === false) {
                    throw new \RuntimeException('Cannot open private diagnostic output.');
                }
                try {
                    chmod($path, 0600);
                    $contents = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if (fwrite($file, $contents) !== strlen($contents)) {
                        throw new \RuntimeException('Diagnostic output was not fully written.');
                    }
                } finally {
                    fclose($file);
                }
                $rows[] = [$league, $report['season_id'], $report['fixture_count'], $report['with_scheduled_time'],
                    $report['odds_matchday'] ?? '—', $report['verified_pre_match_prices'], $report['next_scheduled_start_at'] ?? 'Unknown'];
                $this->line("Snapshot: {$path}");
            } catch (Throwable $exception) {
                $failures++;
                $this->error("{$league}: diagnostic failed (".$exception::class.'). No live state was enabled.');
                report($exception);
            }
        }
        $this->table(['League', 'Season', 'Fixtures', 'With time', 'Odds day', 'Pre-match', 'Next scheduled (UTC)'], $rows);
        $this->line('Scheduled time comes from fixture time.uts. Actual kickoff remains unknown. This is a one-shot diagnostic.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
