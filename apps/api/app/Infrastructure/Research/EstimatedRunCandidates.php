<?php

namespace App\Infrastructure\Research;

use DateTimeImmutable;

final class EstimatedRunCandidates
{
    public const VERSION = 'estimated-history-candidates-1.0.0';

    public function build(array $seasons, array $links): array
    {
        usort($seasons, fn ($a, $b) => [$a['start_at'], $a['competition'], $a['season_id']] <=> [$b['start_at'], $b['competition'], $b['season_id']]);
        $past = $minima = $candidates = $excluded = [];
        foreach ($seasons as $season) {
            $league = $season['competition'];
            $history = $past[$league] ?? [];
            $prior = $history[count($history) - 1] ?? null;
            $older = $history[count($history) - 2] ?? null;
            foreach (['champion-next-win', 'champion-next-season'] as $strategy) {
                // Only the estimated prefix and its two boundary dependencies are added.
                $dependencies = $strategy === 'champion-next-win' ? [$prior, $season] : [$older, $prior, $season];
                if (! array_filter($dependencies, fn ($s) => $s['estimated'] ?? false)) {
                    continue;
                }
                $base = ['id' => $league.':'.$season['season_id'].':'.$strategy, 'competition' => $league,
                    'season_id' => $season['season_id'], 'strategy_id' => $strategy];
                $triggerSeason = $strategy === 'champion-next-win' ? $season : $prior;
                $triggerChampion = $strategy === 'champion-next-win' ? ($prior['champion'] ?? null) : ($older['champion'] ?? null);
                $reason = ! $this->linked($prior, $season, $links) ? 'missing_or_unresolved_previous_link'
                    : (empty($prior['champion']) ? 'previous_champion_unverified' : null);
                if ($reason === null && $strategy === 'champion-next-season') {
                    $reason = ! $this->linked($older, $prior, $links) ? 'missing_or_unresolved_older_link'
                        : (empty($older['champion']) ? 'older_champion_unverified' : null);
                }
                if ($reason === null && ($season['conflicts'] !== [] || $triggerSeason['conflicts'] !== [])) {
                    $reason = 'conflicting_fixture_or_result';
                }
                $trigger = $reason === null ? $this->firstWin($triggerSeason, $triggerChampion['team_uid']) : null;
                if ($reason !== null || $trigger === null) {
                    $excluded[] = [...$base, 'reason' => $reason ?? 'first_win_history_incomplete_or_no_win'];

                    continue;
                }
                $uid = $prior['champion']['team_uid'];
                $first = $strategy === 'champion-next-win' ? $trigger + 1 : 1;
                $fixtures = [];
                for ($day = $first; $day <= min(30, $first + 9); $day++) {
                    $matches = $this->teamDay($season, $uid, $day);
                    if (count($matches) !== 1 || empty($matches[0]['scheduled_start_at'])) {
                        $fixtures[] = ['matchday' => $day, 'scheduled_start_at' => null];

                        continue;
                    }
                    $m = $matches[0];
                    $code = $m['home_team_uid'] === $uid ? 'H' : 'A';
                    $price = $season['prices'][$m['provider_match_id']][$code] ?? [];
                    $minimum = $minima[$league.':'.$m['home_team_uid'].':'.$m['away_team_uid'].':'.$code] ?? null;
                    $fixtures[] = ['provider_match_id' => $m['provider_match_id'], 'matchday' => $day,
                        'home_team' => $m['home_team'], 'away_team' => $m['away_team'], 'outcome' => $code,
                        'scheduled_start_at' => $m['scheduled_start_at'], 'historical_odds_scaled' => $minimum['odds_scaled'] ?? null,
                        'historical_minimum' => $minimum, 'actual_odds_scaled' => $price['odds_scaled'] ?? null,
                        'price_capture_id' => $price['price_capture_id'] ?? null, 'price_provenance' => $price['price_provenance'] ?? null,
                        'price_timing_verified' => false, 'market_blocked' => in_array($day, $season['blocked_matchdays'] ?? [], true),
                        'result_confirmed_at' => $season['confirmations'][$m['provider_match_id']] ?? null,
                        'won' => isset($season['confirmations'][$m['provider_match_id']]) ? $m['result'] === $code : null];
                }
                $candidates[] = [...$base, 'team' => $prior['champion']['team_name'], 'trigger_day' => $trigger,
                    'season_start_at' => $season['start_at'], 'fixtures' => $fixtures,
                    'research_only' => true, 'estimated_timing_dependency' => true,
                    'champion_basis' => array_map(fn ($s) => ['season_id' => $s['season_id'], 'champion' => $s['champion']], array_values(array_filter([$prior, $strategy === 'champion-next-season' ? $older : null]))),
                    'timing_dependencies' => array_map(fn ($s) => ['season_id' => $s['season_id'], 'start_at' => $s['start_at'],
                        'estimated' => $s['estimated'], 'proposals' => $s['timing_proposals']], array_values(array_filter($dependencies))),
                    'continuity_basis' => 'assumed_prefix_adjacency_and_existing_recorded_links'];
            }
            // Only earlier seasons contribute to sizing; never current/future prices.
            foreach ($season['matches'] as $id => $match) {
                if ($season['conflicts'] !== []) {
                    continue;
                }
                foreach ($season['prices'][$id] ?? [] as $code => $quote) {
                    $key = $league.':'.$match['home_team_uid'].':'.$match['away_team_uid'].':'.$code;
                    if (($minima[$key]['odds_scaled'] ?? PHP_INT_MAX) > $quote['odds_scaled']) {
                        $minima[$key] = ['odds_scaled' => $quote['odds_scaled'], 'season_id' => $season['season_id'],
                            'provider_match_id' => $id, 'capture_id' => $quote['price_capture_id'],
                            'season_start_at' => $season['start_at'], 'estimated_timing' => $season['estimated']];
                    }
                }
            }
            $past[$league][] = $season; // Partial seasons stay in position; no skip-back champion.
        }

        return compact('candidates', 'excluded');
    }

    private function linked(?array $a, ?array $b, array $links): bool
    {
        if ($a === null || $b === null || $a['competition'] !== $b['competition']) {
            return false;
        }
        if ($a['estimated'] || $b['estimated']) {
            return (new DateTimeImmutable($b['start_at']))->getTimestamp() - (new DateTimeImmutable($a['start_at']))->getTimestamp() === 6720;
        }

        return in_array($a['competition'].':'.$a['season_id'].':'.$b['season_id'], $links, true);
    }

    private function teamDay(array $season, string $uid, int $day): array
    {
        return array_values(array_filter($season['matches'], fn ($m) => $m['matchday'] === $day
            && ($m['home_team_uid'] === $uid || $m['away_team_uid'] === $uid)));
    }

    private function firstWin(array $season, string $uid): ?int
    {
        foreach (range(1, 30) as $day) {
            $matches = $this->teamDay($season, $uid, $day);
            if (count($matches) !== 1 || ! isset($season['confirmations'][$matches[0]['provider_match_id']])) {
                return null;
            }
            $m = $matches[0];
            if ($m['result'] === ($m['home_team_uid'] === $uid ? 'H' : 'A')) {
                return $day;
            }
        }

        return null;
    }
}
