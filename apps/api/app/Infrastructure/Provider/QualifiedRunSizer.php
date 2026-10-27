<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\RunBudgetEstimate;
use App\Domain\Strategies\ChampionMonitor;
use Illuminate\Support\Facades\DB;

class QualifiedRunSizer
{
    public function __construct(private ChampionMonitoring $monitor, private FixtureBaselineStore $baselines) {}

    public function season(string $competition, string $season, int $capital): array
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'sort' => ['observed_at' => -1]];
        $capture = DB::connection('mongodb')->getDatabase()->provider_captures->findOne(
            ['competition' => $competition, 'season_id' => $season], $options);
        if (! $capture) {
            return ['status' => 'missing_season', 'runs' => []];
        }
        $matches = $capture['matches'];
        $starts = array_filter(array_column($matches, 'scheduled_start_at'));
        $start = $starts === [] ? null : min($starts);
        $runs = [];
        foreach ($this->monitor->forSeason($competition, $season) as $row) {
            if (($row['trigger_day'] ?? 0) < ChampionMonitor::MIN_FIRST_WIN_DAY || empty($row['team_uid']) || empty($row['evidence_ready_at'])) {
                continue;
            }
            $firstDay = $row['strategy_id'] === 'champion-next-win' ? $row['trigger_day'] + 1 : 1;
            $prices = [];
            $fixtures = [];
            $missing = false;
            for ($day = $firstDay; $day <= min(30, $firstDay + 3); $day++) {
                $candidates = array_values(array_filter($matches, fn ($m) => $m['matchday'] === $day
                    && in_array($row['team_uid'], [$m['home_team_uid'], $m['away_team_uid']], true)));
                if (count($candidates) !== 1 || $start === null) {
                    $missing = true;
                    break;
                }
                $fixture = $candidates[0];
                $outcome = $fixture['home_team_uid'] === $row['team_uid'] ? 'H' : 'A';
                $price = $this->baselines->before($competition, $fixture, $outcome, $start);
                $fixtures[] = ['matchday' => $day, 'home_team' => $fixture['home_team'], 'away_team' => $fixture['away_team'],
                    'outcome' => $outcome, 'historical_minimum' => $price];
                if ($price === null) {
                    $missing = true;
                } else {
                    $prices[] = $price['odds_scaled'];
                }
            }
            $estimate = $missing || $prices === [] ? ['status' => 'insufficient_fixture_history', 'plan' => null]
                : (new RunBudgetEstimate)->calculate($prices, $capital);
            $runs[] = ['strategy_id' => $row['strategy_id'], 'team' => $row['team'], 'season_id' => $season,
                'fixtures' => $fixtures, 'estimate' => $estimate, 'actual_staking_changed' => false];
        }

        return ['status' => 'available', 'available_capital_minor' => $capital, 'runs' => $runs,
            'mode' => 'qualified_run_sizing_preview'];
    }
}
