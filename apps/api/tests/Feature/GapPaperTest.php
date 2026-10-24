<?php

namespace Tests\Feature;

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Paper\PaperRunner;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Provider\ChampionMonitoring;
use App\Infrastructure\Provider\ResultRepairRequests;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GapPaperTest extends TestCase
{
    private function scenario(): array
    {
        $now = '2026-10-24T10:00:00+00:00';
        $state = app(PaperLedger::class)->open(700000, 1400000, $now);
        $candidate = ['competition' => 'virtual-football', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
            'version' => '0.2.0-observation', 'status' => 'watching_fixture', 'team' => 'Synthetic', 'team_uid' => '1',
            'attempt' => 1, 'next_match' => ['matchday' => 5], 'evidence_ready_at' => $now];
        $fact = ['observed_at' => $now, 'capture_id' => 'fixture', 'fixture' => ['provider_match_id' => 'vf:match:5',
            'home_team_uid' => '1', 'away_team_uid' => '2', 'scheduled_start_at' => '2026-10-24T10:01:00+00:00',
            'status' => 'scheduled_or_unconfirmed'], 'price' => ['provider_match_id' => 'vf:match:5', 'pre_match_verified' => true,
                'captured_at' => $now, 'capture_id' => 'price', 'prices' => ['H' => '1.5']]];

        return [$state, $candidate, $fact, $now];
    }

    public function test_a_known_collection_gap_prevents_new_reservations(): void
    {
        [$state, $candidate, $fact, $now] = $this->scenario();
        $fact['collection_interrupted'] = true;
        $next = app(PaperLedger::class)->advance($state, [$candidate], ['virtual-football:200:1:5' => $fact], $now);
        $this->assertSame($state, $next);
    }

    public function test_a_late_result_settles_existing_funds_once_at_the_original_price(): void
    {
        [$state, $candidate, $fact, $now] = $this->scenario();
        $ledger = app(PaperLedger::class);
        $pending = $ledger->advance($state, [$candidate], ['virtual-football:200:1:5' => $fact], $now);
        $fact['collection_interrupted'] = true;
        $fact['fixture']['status'] = 'settled';
        $fact['fixture']['result'] = 'H';
        $fact['observed_at'] = '2026-10-25T10:00:00+00:00';
        $fact['price']['prices']['H'] = '99.0';
        $settled = $ledger->advance($pending, [], ['virtual-football:200:1:5' => $fact], $fact['observed_at']);
        $this->assertSame(710000, $settled['cash_minor']);
        $this->assertSame($pending['decisions'], $settled['decisions']);
        $this->assertSame($settled, $ledger->advance($settled, [], ['virtual-football:200:1:5' => $fact], $fact['observed_at']));
    }

    public function test_a_recovery_fixture_missed_during_the_gap_is_not_backdated(): void
    {
        [$state, $candidate, $fact, $now] = $this->scenario();
        $ledger = app(PaperLedger::class);
        $pending = $ledger->advance($state, [$candidate], ['virtual-football:200:1:5' => $fact], $now);
        $fact['fixture']['status'] = 'settled';
        $fact['fixture']['result'] = 'D';
        $fact['observed_at'] = '2026-10-24T11:00:00+00:00';
        $lost = $ledger->advance($pending, [], ['virtual-football:200:1:5' => $fact], $fact['observed_at']);
        $fact['fixture']['provider_match_id'] = 'vf:match:6';
        $fact['fixture']['scheduled_start_at'] = '2026-10-24T10:05:00+00:00';
        $closed = $ledger->advance($lost, [], ['virtual-football:200:1:6' => $fact], $fact['observed_at']);
        $this->assertSame('entry_closed', $closed['runs'][0]['status']);
        $this->assertSame(680000, $closed['cash_minor']);
        $this->assertCount(1, $closed['decisions']);
    }

    public function test_overdue_pending_results_create_a_repair_request_without_changing_money(): void
    {
        [$state, $candidate, $fact, $now] = $this->scenario();
        $pending = app(PaperLedger::class)->advance($state, [$candidate], ['virtual-football:200:1:5' => $fact], $now);
        CarbonImmutable::setTestNow('2026-10-24T10:06:00+00:00');
        try {
            $store = $this->mock(PaperStore::class);
            $store->shouldReceive('read')->once()->andReturn($pending);
            $store->shouldNotReceive('replace');
            $this->mock(ChampionMonitoring::class)->shouldReceive('all')->once()->andReturn([]);
            $this->mock(PaperMarket::class)->shouldReceive('facts')->once()->andReturn([]);
            $this->mock(ResultRepairRequests::class)->shouldReceive('request')->once()->with('virtual-football', '200', 'vf:match:5');
            $this->assertSame('waiting', app(PaperRunner::class)->tick());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
