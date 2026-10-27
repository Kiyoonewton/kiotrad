<?php

namespace App\Infrastructure\Provider;

use App\Domain\Research\SharedCapitalReplay;
use App\Domain\Research\TimingSensitivityReplay;
use App\Domain\Strategies\ChampionMonitor;
use App\Domain\Strategies\HistoricalQuote;
use App\Domain\Strategies\ReplayCapture;
use Illuminate\Support\Facades\DB;

class SharedCapitalResearch
{
    public function __construct(private SimulationHistory $history, private FixtureBaselineStore $baselines,
        private HistoricalQuote $quotes, private SimulationCache $cache) {}

    public function build(int $capital, string $asOf): array
    {
        $seasons = $this->history->seasons('all', null, $asOf);
        foreach (array_keys(config('provider.competitions')) as $competition) {
            $this->baselines->refresh($competition);
        }
        $candidates = [];
        $excluded = [];
        foreach ($this->history->rows($seasons) as $row) {
            if (($row['trigger_day'] ?? 0) < ChampionMonitor::MIN_FIRST_WIN_DAY || empty($row['evidence_ready_at']) || empty($row['team_uid'])) {
                continue;
            }
            $candidate = $this->candidate($row, $asOf);
            if ($candidate === null) {
                $excluded[] = ['competition' => $row['competition'], 'season_id' => $row['season_id'],
                    'strategy_id' => $row['strategy_id'], 'reason' => 'missing_fixture_or_schedule'];
            } else {
                $candidates[] = $candidate;
            }
        }
        $revision = hash('sha256', json_encode([$candidates, $excluded, $capital, $asOf, SharedCapitalReplay::VERSION], JSON_THROW_ON_ERROR));

        return $this->cache->remember('shared-capital:'.$capital, $revision, SimulationCache::HISTORICAL_TTL,
            fn () => [...(new TimingSensitivityReplay)->calculate($candidates, $capital, $asOf),
                'excluded' => $excluded, 'seasons_assessed' => count($seasons),
                'strategy_version' => ChampionMonitor::SIMULATION_VERSION, 'qualification_first_win_day_min' => ChampionMonitor::MIN_FIRST_WIN_DAY]);
    }

    private function candidate(array $row, string $asOf): ?array
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            'sort' => ['observed_at' => 1, '_id' => 1], 'batchSize' => 5];
        $checkpoint = DB::connection('mongodb')->getDatabase()->season_checkpoints->findOne(
            ['_id' => $row['competition'].':'.$row['season_id']], $options);
        $revision = hash('sha256', json_encode([$checkpoint['capture_id'] ?? null, $checkpoint['odds_evidence_revision'] ?? 0, $row['team_uid'], min($asOf, $checkpoint['observed_at'] ?? $asOf)], JSON_THROW_ON_ERROR));
        $evidence = $this->cache->remember('shared-evidence:'.$row['competition'].':'.$row['season_id'].':'.$row['team_uid'],
            $revision, SimulationCache::HISTORICAL_TTL, function () use ($row, $asOf, $options) {
                $captures = [];
                $matches = [];
                $confirmations = [];
                $seasonStart = null;
                $cursor = DB::connection('mongodb')->getDatabase()->provider_captures->find([
                    'competition' => $row['competition'], 'season_id' => $row['season_id'], 'observed_at' => ['$lte' => $asOf]], $options);
                foreach ($cursor as $capture) {
                    foreach ($capture['matches'] ?? [] as $match) {
                        $time = $match['scheduled_start_at'] ?? null;
                        if ($time !== null) {
                            $seasonStart = $seasonStart === null ? $time : min($seasonStart, $time);
                        }
                    }
                    $reduced = (new ReplayCapture)->forTeam($capture, $row['team_uid']);
                    if ($reduced === null) {
                        continue;
                    }
                    $captures[] = $reduced;
                    foreach ($reduced['matches'] as $match) {
                        $matches[$match['provider_match_id']] = $match;
                        if (($match['status'] ?? null) === 'settled' && in_array($match['result'] ?? null, ['H', 'D', 'A'], true)) {
                            $confirmations[$match['provider_match_id'].':'.$match['result']] ??= $capture['observed_at'];
                        }
                    }
                }

                return compact('captures', 'matches', 'confirmations', 'seasonStart');
            });
        ['captures' => $captures, 'matches' => $matches, 'confirmations' => $confirmations, 'seasonStart' => $seasonStart] = $evidence;
        $first = $row['strategy_id'] === 'champion-next-win' ? $row['trigger_day'] + 1 : 1;
        if ($first > 30 || $seasonStart === null) {
            return null;
        }
        $fixtures = [];
        for ($day = $first; $day <= min(30, $first + 3); $day++) {
            $found = array_values(array_filter($matches, fn ($m) => $m['matchday'] === $day));
            if (count($found) !== 1 || empty($found[0]['scheduled_start_at'])) {
                return null;
            }
            $fixture = $found[0];
            $outcome = $fixture['home_team_uid'] === $row['team_uid'] ? 'H' : 'A';
            $minimum = $this->baselines->before($row['competition'], $fixture, $outcome, $seasonStart);
            $quote = $this->quotes->make($captures, $fixture, $row['team_uid'], 0, 1, 1000000000, $asOf);
            $confirmed = $confirmations[$fixture['provider_match_id'].':'.($fixture['result'] ?? '')] ?? null;
            $fixtures[] = ['provider_match_id' => $fixture['provider_match_id'], 'matchday' => $day,
                'home_team' => $fixture['home_team'], 'away_team' => $fixture['away_team'], 'outcome' => $outcome,
                'scheduled_start_at' => $fixture['scheduled_start_at'], 'historical_odds_scaled' => $minimum['odds_scaled'] ?? null,
                'historical_minimum' => $minimum, 'actual_odds_scaled' => $quote['odds_scaled'] ?? null,
                'price_capture_id' => $quote['price_capture_id'] ?? null, 'price_provenance' => $quote['price_provenance'] ?? null,
                'price_timing_verified' => $quote['price_timing_verified'] ?? false,
                'market_blocked' => in_array($day, $row['blocked_matchdays'] ?? [], true),
                'result_confirmed_at' => $confirmed, 'won' => $confirmed === null ? null : $fixture['result'] === $outcome];
        }

        return ['id' => implode(':', [$row['competition'], $row['season_id'], $row['strategy_id']]),
            'competition' => $row['competition'], 'season_id' => $row['season_id'], 'strategy_id' => $row['strategy_id'],
            'team' => $row['team'], 'season_start_at' => $seasonStart, 'fixtures' => $fixtures];
    }
}
