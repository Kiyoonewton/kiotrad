<?php

namespace Tests\Feature;

use App\Domain\Research\ResearchScenario;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ResearchScenarioTest extends TestCase
{
    private function prepared(int $win = 5): array
    {
        $fixtures = [];
        foreach (range(1, 10) as $attempt) {
            $start = CarbonImmutable::parse('2026-10-20T10:00:00Z')->addMinutes(($attempt - 1) * 4);
            $fixtures[] = ['matchday' => $attempt + 5, 'scheduled_start_at' => $start->toIso8601String(),
                'result_confirmed_at' => $start->addMinutes(3)->toIso8601String(),
                'historical_odds_scaled' => 20000, 'actual_odds_scaled' => 20000, 'won' => $attempt === $win];
        }

        return ['as_of' => '2026-10-21T00:00:00Z', 'seasons_assessed' => 3, 'excluded' => [],
            'candidates' => [['id' => 'example', 'competition' => 'virtual-football', 'strategy_id' => 'champion-next-win',
                'season_id' => '200', 'team' => 'Synthetic', 'trigger_day' => 5, 'fixtures' => $fixtures]]];
    }

    private function settings(array $overrides = []): array
    {
        return [...['capital' => 50000, 'qualification' => 5, 'attempts' => 4, 'allowance' => 1, 'strategy' => 'all', 'league' => 'all'], ...$overrides];
    }

    public function test_attempts_change_the_window_and_never_extend_a_four_attempt_run_to_a_later_win(): void
    {
        $source = $this->prepared();
        $four = (new ResearchScenario)->calculate($source, $this->settings());
        $five = (new ResearchScenario)->calculate($source, $this->settings(['attempts' => 5]));
        $this->assertSame('four_losses', $four['runs'][0]['status']);
        $this->assertCount(4, $four['runs'][0]['attempts']);
        $this->assertSame('win', $five['runs'][0]['status']);
        $this->assertCount(5, $five['runs'][0]['attempts']);
        $this->assertSame($this->prepared(), $source);
        $this->assertGreaterThanOrEqual(0, $four['balance_minor']);
    }

    public function test_capital_allowance_qualification_and_strategy_all_affect_the_same_prepared_evidence(): void
    {
        $source = $this->prepared(1);
        $engine = new ResearchScenario;
        $one = $engine->calculate($source, $this->settings());
        $two = $engine->calculate($source, $this->settings(['allowance' => 2]));
        $larger = $engine->calculate($source, $this->settings(['capital' => 100000]));
        $this->assertGreaterThan($two['runs'][0]['estimate']['plan']['profit_per_attempt_minor'], $one['runs'][0]['estimate']['plan']['profit_per_attempt_minor']);
        $this->assertGreaterThan($one['runs'][0]['estimate']['plan']['profit_per_attempt_minor'], $larger['runs'][0]['estimate']['plan']['profit_per_attempt_minor']);
        foreach ([['qualification' => 6], ['strategy' => 'champion-next-season'], ['league' => 'virtual-euro']] as $setting) {
            $this->assertSame([], $engine->calculate($source, $this->settings($setting))['runs']);
        }
        $source['candidates'][0]['trigger_day'] = 4;
        $this->assertSame([], $engine->calculate($source, $this->settings())['runs']);
        $this->assertCount(1, $engine->calculate($source, $this->settings(['qualification' => 4]))['runs']);
    }

    public function test_missing_later_fixtures_do_not_discard_a_shorter_complete_window(): void
    {
        $source = $this->prepared(1);
        $source['candidates'][0]['fixtures'][4] = ['matchday' => 10, 'scheduled_start_at' => null];
        $engine = new ResearchScenario;
        $this->assertCount(1, $engine->calculate($source, $this->settings())['runs']);
        $five = $engine->calculate($source, $this->settings(['attempts' => 5]));
        $this->assertSame([], $five['runs']);
        $this->assertSame('missing_fixture_or_schedule', $five['excluded'][0]['reason']);
    }

    public function test_ten_losses_keep_the_minimum_stake_and_never_overdraw_capital(): void
    {
        $source = $this->prepared(20);
        $view = (new ResearchScenario)->calculate($source, $this->settings(['attempts' => 10, 'capital' => 500000]));
        $this->assertSame('attempt_limit_losses', $view['runs'][0]['status']);
        $this->assertCount(10, $view['runs'][0]['attempts']);
        $this->assertGreaterThanOrEqual(0, $view['balance_minor']);
        foreach ($view['runs'][0]['attempts'] as $attempt) {
            $this->assertGreaterThanOrEqual(10000, $attempt['stake_minor']);
        }
    }

    public function test_growth_beyond_supported_sizing_range_is_explicit_and_does_not_crash(): void
    {
        $source = $this->prepared(1);
        $template = $source['candidates'][0];
        $source['candidates'] = [];
        foreach (range(0, 14) as $index) {
            $start = CarbonImmutable::parse('2026-10-20T10:00:00Z')->addMinutes($index * 4);
            $fixture = [...$template['fixtures'][0], 'scheduled_start_at' => $start->toIso8601String(),
                'result_confirmed_at' => $start->addMinutes(3)->toIso8601String()];
            $source['candidates'][] = [...$template, 'id' => 'growth:'.$index, 'fixtures' => [$fixture]];
        }
        $view = (new ResearchScenario)->calculate($source, $this->settings(['attempts' => 1]));
        $limits = array_filter($view['runs'], fn ($r) => ($r['skip_reason'] ?? '') === 'calculation_range_limit');
        $this->assertNotEmpty($limits);
        $this->assertGreaterThan(1000000000, $view['balance_minor']);
    }
}
