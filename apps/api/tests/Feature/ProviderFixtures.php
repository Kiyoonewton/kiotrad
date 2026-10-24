<?php

namespace Tests\Feature;

trait ProviderFixtures
{
    // Synthetic fixtures, not captured provider datasets.
    private function seasonFixture(): array
    {
        return [
            '_id' => '200', '_utid' => 31867,
            'matches' => [[
                '_id' => 99, '_seasonid' => 200, '_utid' => 31867, 'round' => 1,
                'time' => ['uts' => 1792796452, 'time' => '18:00', 'tz' => 'CDT'],
                'teams' => ['home' => ['uid' => 10, 'name' => 'Home'], 'away' => ['uid' => 20, 'name' => 'Away']],
                'periods' => [], 'result' => ['home' => null, 'away' => null, 'period' => 'nt'],
            ]],
        ];
    }

    private function oddsFixture(): array
    {
        return ['timestamp' => 1792796400000, 'data' => ['odds' => [[
            'id' => 'vf:match:99', 'tournament_id' => 'vf:tournament:31867',
            'teams' => ['home' => ['id' => 'sr:competitor:10'], 'away' => ['id' => 'sr:competitor:20']],
            'market' => [['id' => 1, 'status' => 1, 'outcome' => [
                ['id' => '1', 'odds' => '2.50', 'active' => 1, 'result' => -1],
                ['id' => '2', 'odds' => '3.00', 'active' => 1, 'result' => -1],
                ['id' => '3', 'odds' => '2.80', 'active' => 1, 'result' => -1],
            ]]],
        ]]]];
    }
}
