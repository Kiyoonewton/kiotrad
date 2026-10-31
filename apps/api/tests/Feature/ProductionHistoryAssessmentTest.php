<?php

namespace Tests\Feature;

use App\Infrastructure\History\ProductionHistoryAssessment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProductionHistoryAssessmentTest extends TestCase
{
    private function source(): array
    {
        return ['competition' => 'virtual-euro', 'season_id' => '10', 'source_sha256' => str_repeat('a', 64),
            'matches' => [['provider_match_id' => 'vf:match:99', 'season_id' => '10', 'matchday' => 1,
                'home_team_uid' => '1', 'home_team' => 'Synthetic home', 'away_team_uid' => '2', 'away_team' => 'Synthetic away',
                'scheduled_start_at' => null, 'time_source' => null, 'actual_start_at' => null,
                'home_score' => null, 'away_score' => null, 'result' => 'H', 'status' => 'settled',
                'prices' => ['H' => '2', 'D' => '3', 'A' => '4'], 'market_status' => -3]]];
    }

    public function test_local_installation_refuses_production_command_before_database_access(): void
    {
        config(['kiotrad.local_data_mirror' => true]);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:import-reviewed-history', ['folder' => '/missing', 'plan' => '/missing', 'backup' => '/missing'])
            ->assertFailed();
    }

    public function test_new_fixture_stays_untimed_and_prices_are_not_pretended_pre_match(): void
    {
        $plan = app(ProductionHistoryAssessment::class)->build([$this->source()], [], '2026-10-31T00:00:00Z', []);
        $this->assertSame(1, $plan['new_matches']);
        $this->assertSame(1, $plan['new_prices']);
        $this->assertNull($plan['capture']['matches'][0]['scheduled_start_at']);
        $this->assertFalse($plan['capture']['odds_snapshots'][0]['pre_match_verified']);
        $this->assertSame('production_history_import', $plan['capture']['scope']);
    }

    public function test_recorded_fixture_is_cloned_without_changing_verified_fields(): void
    {
        $source = $this->source();
        $match = array_diff_key($source['matches'][0], array_flip(['prices', 'market_status']));
        $match['scheduled_start_at'] = '2026-10-18T10:00:00Z';
        $plan = app(ProductionHistoryAssessment::class)->build([$source], [['competition' => 'virtual-euro', 'matches' => [$match]]], '2026-10-31T00:00:00Z', []);
        $this->assertSame(0, $plan['new_matches']);
        $this->assertSame($match, $plan['capture']['matches'][0]);
    }

    public function test_conflicting_recorded_times_stop_import(): void
    {
        $source = $this->source();
        $a = $source['matches'][0];
        $a['scheduled_start_at'] = '2026-10-18T10:00:00Z';
        $b = [...$a, 'scheduled_start_at' => '2026-10-18T11:00:00Z'];
        $this->expectException(RuntimeException::class);
        app(ProductionHistoryAssessment::class)->build([$source], [['competition' => 'virtual-euro', 'matches' => [$a, $b]]], '2026-10-31T00:00:00Z', []);
    }

    public function test_foreign_quote_identity_stops_import(): void
    {
        $this->expectException(RuntimeException::class);
        app(ProductionHistoryAssessment::class)->build([$this->source()], [['competition' => 'virtual-football',
            'odds_snapshots' => [['provider_match_id' => 'vf:match:99', 'prices' => ['H' => '2', 'D' => '3', 'A' => '4']]]]], '2026-10-31T00:00:00Z', []);
    }
}
