<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\FinalStandings;
use App\Infrastructure\Provider\SeasonCapture;
use Illuminate\Console\Command;
use Throwable;

class InspectStandings extends Command
{
    protected $signature = 'provider:standings {competition} {season}';

    protected $description = 'Verify an official final table against fixture scores and persist a season-only capture';

    public function handle(SeasonCapture $capture, FinalStandings $standings, CollectionStore $store): int
    {
        $competition = $this->argument('competition');
        $season = $this->argument('season');
        if (! array_key_exists($competition, config('provider.competitions')) || ! ctype_digit($season) || strlen($season) > 20) {
            $this->error('Use a configured competition and a numeric season ID.');

            return self::FAILURE;
        }
        try {
            $report = $standings->attach($capture->run($competition, $season, 'reconciliation', [], 0));
            $id = $store->save($report);
            $this->table(['Rank', 'Team', 'Played', 'W', 'D', 'L', 'GF', 'GA', 'GD', 'Points'], array_map(fn ($r) => [
                $r['position'], $r['team_name'], $r['played'], $r['won'], $r['drawn'], $r['lost'],
                $r['goals_for'], $r['goals_against'], $r['goal_difference'], $r['points'],
            ], $report['standings']['rows'] ?? []));
            $this->line('Status: '.$report['standings']['status']);
            $this->line('Champion: '.($report['standings']['champion']['team_name'] ?? 'Unconfirmed'));
            $this->line('Capture: '.$id);

            return $report['standings']['champion'] !== null ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Standings inspection failed; no champion was assumed.');

            return self::FAILURE;
        }
    }
}
