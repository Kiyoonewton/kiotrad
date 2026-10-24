<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\ProviderProbe;
use Illuminate\Console\Command;
use Throwable;

class CollectProvider extends Command
{
    protected $signature = 'provider:collect {competition? : Omit for all three leagues}';

    protected $description = 'Persist one normalized current-season observation per league in KioTrad MongoDB';

    public function handle(ProviderProbe $probe, CollectionStore $store): int
    {
        $profiles = config('provider.competitions');
        $league = $this->argument('competition');
        if ($league !== null && ! isset($profiles[$league])) {
            $this->error('Use a configured competition ID.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($league === null ? array_keys($profiles) : [$league] as $competition) {
            try {
                $report = $probe->run($competition);
                $capture = $store->save($report);
                $this->line("{$competition}: season {$report['season_id']}; {$report['fixture_count']} fixtures; capture {$capture}");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$competition}: collection failed; rerun to retry. No checkpoint advanced before a durable capture.");
                report($exception);
            }
        }
        $this->line('One-shot collection only. No signals or paper bets were executed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
