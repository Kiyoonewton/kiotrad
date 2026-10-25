<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverAllHistoricalOdds extends Command
{
    protected $signature = 'provider:recover-odds-all {competition=all} {--from-day=1} {--to-day=30}';

    protected $description = 'Scan stored seasons for missing historical odds; reruns resume from saved captures';

    public function handle(): int
    {
        $competition = $this->argument('competition');
        $profiles = config('provider.competitions');
        $from = filter_var($this->option('from-day'), FILTER_VALIDATE_INT);
        $to = filter_var($this->option('to-day'), FILTER_VALIDATE_INT);
        if (($competition !== 'all' && ! array_key_exists($competition, $profiles))
            || $from === false || $to === false || $from < 1 || $to > 30 || $from > $to) {
            $this->error('Use all or a configured league and matchdays 1–30.');

            return self::FAILURE;
        }
        $failed = 0;
        $processed = 0;
        foreach (array_keys($profiles) as $league) {
            if ($competition !== 'all' && $competition !== $league) {
                continue;
            }
            try {
                $ids = DB::connection('mongodb')->getDatabase()->provider_captures
                    ->distinct('season_id', ['competition' => $league]);
                $ids = array_values(array_unique(array_filter(array_map('strval', $ids),
                    fn ($id) => ctype_digit($id) && strlen($id) <= 20)));
                sort($ids, SORT_NUMERIC);
                $this->line($league.': '.count($ids).' stored seasons queued for odds checks.');
                foreach ($ids as $id) {
                    $processed++;
                    $this->line($league.' / '.$id);
                    $result = $this->call('provider:recover-odds', ['competition' => $league, 'seasons' => [$id],
                        '--from-day' => $from, '--to-day' => $to]);
                    if ($result !== self::SUCCESS) {
                        $failed++;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error($league.': scan interrupted; rerun to retry missing odds.');
                $failed++;
            }
        }
        $this->line('Seasons checked: '.$processed.'; failed or deferred checks: '.$failed.'. Rerun retries missing rounds.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
