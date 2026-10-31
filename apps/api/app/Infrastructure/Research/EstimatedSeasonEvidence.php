<?php

namespace App\Infrastructure\Research;

use App\Domain\Strategies\HistoricalQuote;
use App\Infrastructure\Provider\SeasonCompleteness;
use DateTimeImmutable;
use RuntimeException;

final class EstimatedSeasonEvidence
{
    public function fold(array $row, array $captures, string $asOf): array
    {
        $estimated = $row['status'] === 'estimated_unbounded';
        $start = $estimated ? $row['estimated_day1_utc'] : $row['recorded_day1_utc'];
        $matches = $conflicts = $confirmations = [];
        foreach ($captures as &$capture) {
            $capture['matches'] ??= [];
            foreach ($capture['matches'] as &$match) {
                $id = $match['provider_match_id'];
                if (($match['season_id'] ?? null) !== (string) $row['season_id'] || ! is_int($match['matchday'])
                    || $match['matchday'] < 1 || $match['matchday'] > 30 || $match['home_team_uid'] === $match['away_team_uid']) {
                    throw new RuntimeException('Source fixture identity does not match timing season.');
                }
                if ($estimated && ! empty($match['scheduled_start_at'])) {
                    throw new RuntimeException('Estimated season now has recorded timing; regenerate the audit.');
                }
                if ($estimated) {
                    $match['scheduled_start_at'] = (new DateTimeImmutable($start))->modify('+'.(($match['matchday'] - 1) * 220).' seconds')->format('Y-m-d\TH:i:sP');
                    $match['time_source'] = 'hypothetical_112_minute_season_220_second_matchday';
                }
                $prior = $matches[$id] ?? null;
                if ($prior !== null) {
                    foreach (['home_team_uid', 'away_team_uid', 'matchday', 'scheduled_start_at'] as $field) {
                        if ($prior[$field] !== $match[$field]) {
                            $conflicts[$id] = true;
                        }
                    }
                    if ($prior['result'] !== null && $match['result'] !== null && $prior['result'] !== $match['result']) {
                        $conflicts[$id] = true;
                    }
                }
                if (($match['status'] ?? '') === 'settled' && in_array($match['result'] ?? null, ['H', 'D', 'A'], true)) {
                    $confirmations[$id] ??= $capture['observed_at'];
                }
                if ($prior === null || $match['result'] !== null) {
                    $matches[$id] = $match;
                }
            }
            unset($match);
        }
        unset($capture);
        if (! $estimated && $matches !== []) {
            $starts = array_filter(array_column($matches, 'scheduled_start_at'));
            if ($starts === [] || (new DateTimeImmutable(min($starts)))->getTimestamp() !== (new DateTimeImmutable($start))->getTimestamp()) {
                throw new RuntimeException('Recorded anchor disagrees with current database; regenerate the audit.');
            }
        }
        $champion = null;
        $reason = $conflicts ? 'conflicting_fixture_or_result' : 'incomplete_season';
        $complete = $conflicts === [] && (new SeasonCompleteness)->inspect(array_values($matches))['results_complete']
            && count(array_filter($matches, fn ($m) => in_array($m['result'], ['H', 'D', 'A'], true))) === 240;
        if ($complete) {
            $points = $names = [];
            foreach ($matches as $match) {
                foreach (['home' => 'H', 'away' => 'A'] as $side => $code) {
                    $uid = $match[$side.'_team_uid'];
                    $names[$uid] = $match[$side.'_team'];
                    $points[$uid] = ($points[$uid] ?? 0) + ($match['result'] === $code ? 3 : ($match['result'] === 'D' ? 1 : 0));
                }
            }
            $leaders = array_keys($points, max($points), true);
            $reason = count($leaders) === 1 ? null : 'tied_points_champion_unresolved';
            if (count($leaders) === 1) {
                $uid = (string) $leaders[0];
                $champion = ['team_uid' => $uid, 'team_name' => $names[$uid], 'basis' => 'unique_points_leader_from_240_results'];
            }
        }
        $prices = [];
        foreach ($matches as $id => $match) {
            if (isset($conflicts[$id])) {
                continue;
            }
            foreach (['H' => 'home', 'A' => 'away'] as $code => $side) {
                $quote = (new HistoricalQuote)->make($captures, $match, $match[$side.'_team_uid'], 0, 1, 1000000000, $asOf);
                if (isset($quote['odds_scaled'])) {
                    $prices[$id][$code] = $quote;
                }
            }
        }

        return ['competition' => $row['competition'], 'season_id' => (string) $row['season_id'], 'start_at' => $start,
            'estimated' => $estimated, 'timing_proposals' => $row['proposals'] ?? [], 'matches' => $matches,
            'prices' => $prices, 'confirmations' => $confirmations, 'champion' => $champion,
            'champion_issue' => $reason, 'conflicts' => array_keys($conflicts), 'complete' => $complete];
    }
}
