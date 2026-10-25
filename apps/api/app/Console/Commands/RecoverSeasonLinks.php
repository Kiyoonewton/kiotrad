<?php

namespace App\Console\Commands;

use App\Domain\Strategies\SimulationSeasonLinks;
use App\Infrastructure\Provider\SeasonDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverSeasonLinks extends Command
{
    protected $signature = 'provider:recover-season-links {competition=all}';

    protected $description = 'Recover historical season links from the provider catalog without changing collector state';

    public function handle(SeasonDiscovery $discovery): int
    {
        $profiles = config('provider.competitions');
        $competition = $this->argument('competition');
        if ($competition !== 'all' && ! array_key_exists($competition, $profiles)) {
            $this->error('Use all or a configured competition.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($profiles as $id => $profile) {
            if ($competition !== 'all' && $competition !== $id) {
                continue;
            }
            try {
                $report = $discovery->discover($profile);
                $observed = now('UTC')->toIso8601String();
                $catalog = array_map(fn ($season) => ['season_id' => (string) $season['_id'],
                    'start_uts' => $season['start']['uts'] ?? null, 'observed_at' => $observed], $report['seasons']);
                $links = (new SimulationSeasonLinks)->merge([], $catalog, $observed);
                if ($links === []) {
                    throw new \RuntimeException('Catalog has insufficient or ambiguous start times; no links written.');
                }
                $operations = array_map(fn ($link) => ['updateOne' => [
                    ['_id' => $id.':'.$link['from_season_id'].':'.$link['to_season_id']],
                    ['$setOnInsert' => ['competition' => $id, ...$link]], ['upsert' => true],
                ]], $links);
                DB::connection('mongodb')->getDatabase()->provider_catalog_links->bulkWrite($operations, ['ordered' => false]);
                $this->line($id.': '.count($links).' provider-confirmed links saved (existing links preserved).');
            } catch (Throwable $exception) {
                report($exception);
                $this->error($id.': catalog recovery failed; no collector state changed.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
