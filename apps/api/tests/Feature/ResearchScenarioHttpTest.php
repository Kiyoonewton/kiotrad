<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\PreparedResearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class ResearchScenarioHttpTest extends TestCase
{
    private array $settings = ['capital' => 50000, 'qualification' => 5, 'attempts' => 4, 'allowance' => 1, 'strategy' => 'all', 'league' => 'all'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cache.stores.redis' => ['driver' => 'array']]);
    }

    private function url(array $changes = []): string
    {
        return '/api/v1/research/scenario?'.http_build_query([...$this->settings, ...$changes]);
    }

    public function test_a_cold_request_does_not_read_mongodb_or_start_source_reconstruction(): void
    {
        DB::shouldReceive('connection')->never();
        $this->getJson($this->url())->assertStatus(202)->assertJsonPath('status', 'warming');
    }

    public function test_cached_prepared_data_is_reused_and_settings_and_source_revisions_are_isolated(): void
    {
        DB::shouldReceive('connection')->never();
        $key = (new ReflectionMethod(PreparedResearch::class, 'key'))->invoke(new PreparedResearch);
        $source = ['as_of' => '2026-10-21T00:00:00Z', 'revision' => 'first', 'seasons_assessed' => 0, 'candidates' => [], 'excluded' => []];
        Cache::store('redis')->put($key, $source, 100);
        $this->getJson($this->url())->assertOk()->assertJsonPath('scenario_cache', 'miss')->assertJsonPath('starting_capital_minor', 5000000);
        $this->getJson($this->url())->assertOk()->assertJsonPath('scenario_cache', 'hit');
        $this->getJson($this->url(['capital' => 100000, 'allowance' => 2]))->assertOk()
            ->assertJsonPath('scenario_cache', 'miss')->assertJsonPath('allowance_multiplier', 2)->assertJsonPath('starting_capital_minor', 10000000);
        $this->getJson($this->url(['capital_mode' => 'fixed', 'profit_stop' => 20000]))->assertOk()
            ->assertJsonPath('scenario_cache', 'miss')->assertJsonPath('capital_mode', 'fixed')->assertJsonPath('profit_stop_minor', 2000000);
        $this->getJson($this->url(['capital_mode' => 'fixed', 'profit_stop' => 20000]))->assertOk()->assertJsonPath('scenario_cache', 'hit');
        $carry = ['staking_model' => 'payout_carry_v1', 'next_win_attempts' => 4, 'next_season_attempts' => 5];
        $this->getJson($this->url($carry))->assertOk()->assertJsonPath('scenario_cache', 'miss')->assertJsonPath('retrospective_odds_sizing', true);
        $this->getJson($this->url($carry))->assertOk()->assertJsonPath('scenario_cache', 'hit');
        $this->getJson($this->url([...$carry, 'next_win_qualification' => 6]))->assertOk()->assertJsonPath('scenario_cache', 'miss');
        Cache::store('redis')->put($key, [...$source, 'revision' => 'repaired'], 100);
        $this->getJson($this->url())->assertOk()->assertJsonPath('scenario_cache', 'miss')->assertJsonPath('data_revision', 'repaired');
        $this->assertSame(700000, config('kiotrad.simulation_capital_minor'));
        config(['database.connections.mongodb.database' => 'another_local_snapshot']);
        $this->getJson($this->url())->assertStatus(202)->assertJsonPath('status', 'warming');
    }

    public function test_entered_capital_changes_affordability_without_reading_or_rebuilding_source_data(): void
    {
        DB::shouldReceive('connection')->never();
        $key = (new ReflectionMethod(PreparedResearch::class, 'key'))->invoke(new PreparedResearch);
        $fixtures = [];
        foreach ([26500, 11000, 11000, 11000] as $index => $odds) {
            $fixtures[] = ['matchday' => $index + 6,
                'scheduled_start_at' => sprintf('2026-10-20T10:%02d:00Z', $index * 4),
                'result_confirmed_at' => sprintf('2026-10-20T10:%02d:00Z', $index * 4 + 3),
                'historical_odds_scaled' => $odds, 'actual_odds_scaled' => 27000, 'won' => $index === 0];
        }
        $source = ['as_of' => '2026-10-21T00:00:00Z', 'revision' => 'synthetic-affordability', 'seasons_assessed' => 1,
            'excluded' => [], 'candidates' => [['id' => 'synthetic', 'competition' => 'virtual-football',
                'strategy_id' => 'champion-next-win', 'trigger_day' => 5, 'fixtures' => $fixtures]]];
        Cache::store('redis')->put($key, $source, 100);
        $small = $this->getJson($this->url(['capital' => 7000, 'capital_mode' => 'fixed']));
        $small->assertOk()->assertJsonPath('runs.0.status', 'skipped_budget');
        $largeUrl = $this->url(['capital' => 1000000, 'capital_mode' => 'fixed']);
        $large = $this->getJson($largeUrl);
        $large->assertOk()->assertJsonPath('runs.0.status', 'win')->assertJsonPath('starting_capital_minor', 100000000)
            ->assertJsonPath('runs.0.sizing_capital_at_entry_minor', 100000000)->assertJsonPath('scenario_cache', 'miss');
        $this->getJson($largeUrl)->assertOk()->assertJsonPath('scenario_cache', 'hit')
            ->assertJsonPath('runs.0.status', 'win');
        $this->assertSame($source, Cache::store('redis')->get($key));
        $this->assertSame(700000, config('kiotrad.simulation_capital_minor'));
    }

    public function test_invalid_settings_never_reach_the_replay(): void
    {
        $this->mock(PreparedResearch::class)->shouldReceive('scenario')->never();
        foreach ([['staking_model' => 'unknown'], ['next_win_attempts' => 0], ['next_season_attempts' => 11], ['next_win_qualification' => 31], ['next_season_qualification' => 1.5], ['capital' => 99], ['capital' => 10000001], ['qualification' => 31], ['attempts' => 0],
            ['capital_mode' => 'unknown'], ['profit_stop' => -1], ['profit_stop' => 10000001], ['attempts' => 11], ['allowance' => 3], ['qualification' => 4.5], ['strategy' => 'unknown'], ['league' => 'unknown']] as $bad) {
            $this->getJson($this->url($bad))->assertStatus(422);
        }
    }
}
