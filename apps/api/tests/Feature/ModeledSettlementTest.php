<?php

namespace Tests\Feature;

use App\Domain\Research\SharedCapitalReplay;
use App\Domain\Research\TimingSensitivityReplay;
use Tests\TestCase;

class ModeledSettlementTest extends TestCase
{
    private function candidate(string $id, string $start, bool $won = true): array
    {
        $fixtures = [];
        foreach (range(0, 3) as $i) {
            $fixtures[] = ['matchday' => $i + 1,
                'scheduled_start_at' => (new \DateTimeImmutable($start))->modify('+'.($i * 220).' seconds')->format(DATE_ATOM),
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000,
                'won' => $won, 'result_confirmed_at' => '2026-10-24T10:00:00Z'];
        }

        return ['id' => $id, 'fixtures' => $fixtures];
    }

    public function test_archive_receipt_is_preserved_but_no_longer_blocks_later_days(): void
    {
        $a = $this->candidate('a', '2026-10-19T10:00:00Z');
        $b = $this->candidate('b', '2026-10-20T10:00:00Z');
        $view = (new TimingSensitivityReplay)->calculate([$a, $b], 5000000, '2026-10-25T00:00:00Z');
        $this->assertSame(['win', 'win'], array_column($view['runs'], 'status'));
        $this->assertSame('2026-10-19T10:03:21.000000Z', $view['runs'][0]['released_at']);
        $this->assertSame('2026-10-24T10:00:00.000000Z', $view['runs'][0]['result_observed_at']);
        $this->assertNull($view['runs'][0]['event_finish_at']);
        $this->assertSame('modeled_settlement', $view['timing_quality']);
        $this->assertSame('2026-10-24T10:00:00Z', $a['fixtures'][0]['result_confirmed_at']);
        $this->assertSame(201, $view['settlement_model']['seconds']);
    }

    public function test_sensitivity_replays_balance_and_allocation_from_the_same_starting_capital(): void
    {
        $view = (new TimingSensitivityReplay)->calculate([
            $this->candidate('a', '2026-10-19T10:00:00Z'),
            $this->candidate('b', '2026-10-19T10:03:10Z'),
        ], 5000000, '2026-10-25T00:00:00Z');
        $this->assertSame('skipped_overlap', $view['runs'][1]['status']);
        $this->assertTrue($view['timing_sensitive']);
        $scenarios = array_column($view['timing_sensitivity'], null, 'settlement_seconds');
        $this->assertSame(1, $scenarios[201]['completed_runs']);
        $this->assertSame(2, $scenarios[180]['completed_runs']);
        $this->assertSame(['b'], $scenarios[180]['accepted_only_in_scenario']);
        $this->assertGreaterThan($scenarios[201]['balance_minor'], $scenarios[180]['balance_minor']);
    }

    public function test_model_cannot_invent_a_missing_result_or_use_future_evidence(): void
    {
        $candidate = $this->candidate('a', '2026-10-19T10:00:00Z');
        $view = (new SharedCapitalReplay)->calculate([$candidate], 5000000, '2026-10-20T00:00:00Z', 201);
        $this->assertSame('awaiting_result', $view['runs'][0]['status']);
        $this->assertNull($view['runs'][0]['net_minor']);
        $candidate['fixtures'][0]['result_confirmed_at'] = null;
        $candidate['fixtures'][0]['won'] = null;
        $view = (new SharedCapitalReplay)->calculate([$candidate], 5000000, '2026-10-25T00:00:00Z', 201);
        $this->assertSame('awaiting_result', $view['runs'][0]['status']);
    }

    public function test_missing_prices_remain_unresolved_under_the_model(): void
    {
        $candidate = $this->candidate('missing', '2026-10-19T10:00:00Z');
        $candidate['fixtures'][0]['actual_odds_scaled'] = null;
        $view = (new TimingSensitivityReplay)->calculate([$candidate], 5000000, '2026-10-25T00:00:00Z');
        $this->assertSame('unfunded_entry', $view['runs'][0]['status']);
        $this->assertSame(5000000, $view['balance_minor']);
        $this->assertNull($view['runs'][0]['net_minor']);
    }

    public function test_equal_time_entry_still_clashes_and_season_boundary_releases(): void
    {
        $view = (new SharedCapitalReplay)->calculate([
            $this->candidate('day30', '2026-10-19T10:00:00Z'),
            $this->candidate('equal', '2026-10-19T10:03:21Z'),
            $this->candidate('next-season', '2026-10-19T10:05:40Z'),
        ], 5000000, '2026-10-25T00:00:00Z', 201);
        $this->assertSame(['win', 'skipped_overlap', 'win'], array_column($view['runs'], 'status'));
        $this->assertSame('modeled_settlement_offset', $view['runs'][1]['overlap_basis']);
    }

    public function test_slow_model_stops_when_previous_result_is_not_before_next_attempt(): void
    {
        $view = (new SharedCapitalReplay)->calculate([
            $this->candidate('loss', '2026-10-19T10:00:00Z', false),
            $this->candidate('later', '2026-10-19T11:00:00Z'),
        ], 5000000, '2026-10-25T00:00:00Z', 220);
        $this->assertSame('timing_model_entry_stop', $view['runs'][0]['status']);
        $this->assertLessThan(0, $view['runs'][0]['net_minor']);
        $this->assertSame('win', $view['runs'][1]['status']);
        $this->assertSame(1, $view['runs'][1]['estimate']['allowance_multiplier']);
    }
}
