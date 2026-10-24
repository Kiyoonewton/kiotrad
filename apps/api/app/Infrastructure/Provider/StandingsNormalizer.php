<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class StandingsNormalizer
{
    public function normalize(FeedObservation $feed, string $season, int $tournament, array $matches): array
    {
        $data = $feed->document['data'];
        if ((string) ($data['_id'] ?? '') !== $season || (int) ($data['_utid'] ?? 0) !== $tournament) {
            throw new RuntimeException('Standings season or league mismatch.');
        }
        if (count(array_filter($matches, fn ($m) => ($m['season_id'] ?? null) !== $season)) > 0) {
            throw new RuntimeException('Standings fixtures belong to another season.');
        }
        $tables = array_values(array_filter($data['tables'] ?? [], fn ($t) => (string) ($t['seasonid'] ?? '') === $season
            && ($t['presentationid'] ?? null) === 0 && (int) ($t['tournament']['_utid'] ?? 0) === $tournament));
        if (count($tables) !== 1 || count($tables[0]['tablerows'] ?? []) !== 16) {
            throw new RuntimeException('No unambiguous sixteen-team overall table.');
        }
        $totals = $this->fixtureTotals($matches);
        $rows = [];
        $positions = [];
        foreach ($tables[0]['tablerows'] as $raw) {
            $uid = (string) ($raw['team']['uid'] ?? '');
            $name = $raw['team']['name'] ?? null;
            $position = $this->integer($raw, 'pos', 1, 16);
            if (! ctype_digit($uid) || ! is_string($name) || $name === '' || isset($rows[$uid]) || isset($positions[$position])) {
                throw new RuntimeException('Duplicate or invalid standing identity/rank.');
            }
            $positions[$position] = true;
            $row = ['team_uid' => $uid, 'team_name' => $name, 'position' => $position];
            foreach (['played' => 'total', 'won' => 'winTotal', 'drawn' => 'drawTotal', 'lost' => 'lossTotal',
                'goals_for' => 'goalsForTotal', 'goals_against' => 'goalsAgainstTotal',
                'goal_difference' => 'goalDiffTotal', 'points' => 'pointsTotal'] as $field => $source) {
                $row[$field] = $this->integer($raw, $source, $field === 'goal_difference' ? -10000 : 0, 10000);
            }
            if ($row['played'] > 30 || $row['played'] !== $row['won'] + $row['drawn'] + $row['lost']
                || $row['points'] !== 3 * $row['won'] + $row['drawn']
                || $row['goal_difference'] !== $row['goals_for'] - $row['goals_against']) {
                throw new RuntimeException('Standings arithmetic is inconsistent.');
            }
            $rows[$uid] = $row;
        }
        $aligned = count($totals) === 16;
        foreach ($rows as $uid => $row) {
            foreach (['played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'points'] as $field) {
                $aligned = $aligned && isset($totals[$uid]) && $row[$field] === $totals[$uid][$field];
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $a['position'] <=> $b['position']);
        $ordered = true;
        for ($i = 1; $i < count($rows); $i++) {
            $before = array_map(fn ($f) => $rows[$i - 1][$f], ['points', 'goal_difference', 'goals_for']);
            $after = array_map(fn ($f) => $rows[$i][$f], ['points', 'goal_difference', 'goals_for']);
            $ordered = $ordered && $before >= $after;
        }
        $complete = (new SeasonCompleteness)->inspect($matches)['results_complete'];
        $verified = $complete && $aligned && $ordered && count(array_filter($rows, fn ($r) => $r['played'] === 30)) === 16;

        return [
            'status' => $verified ? 'verified_final' : 'blocked',
            'season_id' => $season, 'table_id' => (string) ($tables[0]['_id'] ?? ''),
            'observed_at' => $feed->receivedAt->toIso8601String(),
            'requested_at' => $feed->requestedAt->toIso8601String(),
            'source' => 'stats_season_tables', 'ranking_source' => 'provider.pos',
            'source_url' => $feed->url, 'provider_generated_at_seconds' => $feed->document['_dob'] ?? null,
            'rules_text' => $tables[0]['rules']['name'] ?? null,
            'fixture_totals_aligned' => $aligned, 'rank_order_consistent' => $ordered,
            'rows' => $rows, 'champion' => $verified ? $rows[0] : null,
        ];
    }

    private function fixtureTotals(array $matches): array
    {
        $totals = [];
        foreach ($matches as $match) {
            foreach (['home', 'away'] as $side) {
                $uid = $match[$side.'_team_uid'];
                $totals[$uid] ??= array_fill_keys(['played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'points'], 0);
                if ($match['status'] !== 'settled') {
                    continue;
                }
                $other = $side === 'home' ? 'away' : 'home';
                $for = $match[$side.'_score'];
                $against = $match[$other.'_score'];
                $result = $for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost');
                $totals[$uid]['played']++;
                $totals[$uid][$result]++;
                $totals[$uid]['goals_for'] += $for;
                $totals[$uid]['goals_against'] += $against;
                $totals[$uid]['points'] += $result === 'won' ? 3 : ($result === 'drawn' ? 1 : 0);
            }
        }

        return $totals;
    }

    private function integer(array $row, string $key, int $min, int $max): int
    {
        $value = $row[$key] ?? null;
        if ((! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/D', $value)))
            || $value < $min || $value > $max) {
            throw new RuntimeException('Invalid standings number.');
        }

        return (int) $value;
    }
}
