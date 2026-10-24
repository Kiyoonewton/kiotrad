<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordMarketBlock extends Command
{
    protected $signature = 'provider:market-block {competition} {season} {matchday} {--evidence=} {--reason=} {--confirmed : Operator has verified an actual market block}';

    protected $description = 'Retain an operator-confirmed market blockage with private evidence; never infer from missing odds';

    public function handle(): int
    {
        $league = $this->argument('competition');
        $season = $this->argument('season');
        $day = filter_var($this->argument('matchday'), FILTER_VALIDATE_INT);
        $root = realpath(storage_path('app/private/provider-evidence'));
        $path = realpath((string) $this->option('evidence'));
        $reason = trim((string) $this->option('reason'));
        if (! $this->option('confirmed') || ! isset(config('provider.competitions')[$league]) || ! ctype_digit($season)
            || $day === false || $day < 1 || $day > 30 || ! $root || ! $path || ! is_file($path)
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || $reason === '' || mb_strlen($reason) > 500) {
            $this->error('Provide valid league/season/day, private evidence file, reason and explicit --confirmed.');

            return self::FAILURE;
        }
        $hash = hash_file('sha256', $path);
        $id = hash('sha256', $league.':'.$season.':'.$day.':'.$hash);
        DB::connection('mongodb')->getDatabase()->market_blockages->updateOne(['_id' => $id], ['$setOnInsert' => [
            'classification' => 'operator_confirmed', 'competition' => $league, 'season_id' => $season,
            'matchday' => $day, 'reason' => $reason, 'observed_at' => now('UTC')->toIso8601String(),
            'evidence_path' => substr($path, strlen($root) + 1), 'evidence_sha256' => $hash,
        ]], ['upsert' => true]);
        $this->info('Confirmed block retained. Collection/recovery rules are unchanged.');

        return self::SUCCESS;
    }
}
