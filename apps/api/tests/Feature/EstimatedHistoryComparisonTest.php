<?php

namespace Tests\Feature;

use App\Infrastructure\Research\EstimatedHistoryComparison;
use App\Infrastructure\Research\EstimatedRunCandidates;
use App\Infrastructure\Research\EstimatedSeasonEvidence;
use App\Infrastructure\Research\EstimatedTimingInput;
use RuntimeException;
use Tests\TestCase;

class EstimatedHistoryComparisonTest extends TestCase
{
    use EstimatedHistoryFixtures;

    public function test_comparison_reuses_engine_preserves_baseline_and_labels_overlap_dependency(): void
    {
        $seasons = [];
        foreach (['100' => '10:00:00', '200' => '11:52:00'] as $id => $time) {
            $id = (string) $id;
            $seasons[] = (new EstimatedSeasonEvidence)->fold($this->timingRow($id, '2026-10-17T'.$time.'+00:00'),
                [$this->seasonCapture($id, $id === '200' ? 5 : 1)], '2026-11-01T00:00:00+00:00');
        }
        $added = (new EstimatedRunCandidates)->build($seasons, []);
        $template = $added['candidates'][0];
        $recorded = ['candidates' => [], 'excluded' => [], 'as_of' => '2026-11-01T00:00:00+00:00', 'seasons_assessed' => 2];
        $before = $recorded;
        $result = (new EstimatedHistoryComparison)->calculate($recorded, $added, $this->scenarioSettings(), ['sha256' => 'test']);
        $this->assertSame($before, $recorded);
        $this->assertSame(5000000, $result['recorded_only']['balance_minor']);
        $this->assertGreaterThan(5000000, $result['with_estimated_history']['balance_minor']);
        $this->assertFalse($result['benchmark_eligible']);
        $this->assertTrue($result['research_only']);
        $run = $result['with_estimated_history']['runs'][0];
        $this->assertSame('hypothetical_kickoff_and_modeled_settlement', $run['overlap_basis']);
        $this->assertNotEmpty($run['timing_dependencies']);
        $recorded['candidates'] = [$template];
        $preserved = (new EstimatedHistoryComparison)->calculate($recorded, $added, $this->scenarioSettings(), []);
        $this->assertSame([$template['id']], $preserved['existing_candidates_preserved']);
        $this->assertCount(1, $preserved['with_estimated_history']['runs']);
    }

    public function test_price_gaps_and_operator_blocks_do_not_produce_funded_wins(): void
    {
        $seasons = [];
        foreach (['100' => '10:00:00', '200' => '11:52:00'] as $id => $time) {
            $id = (string) $id;
            $seasons[] = (new EstimatedSeasonEvidence)->fold($this->timingRow($id, '2026-10-17T'.$time.'+00:00'),
                [$this->seasonCapture($id, $id === '200' ? 5 : 1)], '2026-11-01T00:00:00+00:00');
        }
        $seasons[1]['blocked_matchdays'] = [6];
        $additional = (new EstimatedRunCandidates)->build($seasons, []);
        $recorded = ['candidates' => [], 'excluded' => [], 'as_of' => '2026-11-01T00:00:00+00:00', 'seasons_assessed' => 2];
        $result = (new EstimatedHistoryComparison)->calculate($recorded, $additional, $this->scenarioSettings(), []);
        $this->assertSame('unfunded_entry', $result['with_estimated_history']['runs'][0]['status']);
        $this->assertSame(5000000, $result['with_estimated_history']['balance_minor']);
        $seasons[1]['blocked_matchdays'] = [];
        $seasons[0]['prices'] = [];
        $result = (new EstimatedHistoryComparison)->calculate($recorded, (new EstimatedRunCandidates)->build($seasons, []), $this->scenarioSettings(), []);
        $this->assertSame('insufficient_fixture_history', $result['with_estimated_history']['runs'][0]['status']);
    }

    public function test_report_anchor_arithmetic_is_checked_and_inputs_remain_separate(): void
    {
        $report = ['version' => 'older-timing-proposals-1.0.0', 'policy' => ['applied' => false, 'normal_season_seconds' => 6720],
            'input_sha256s' => ['timing' => str_repeat('a', 64), 'discovery' => str_repeat('b', 64)],
            'seasons' => [$this->timingRow('100', '2026-10-17T10:00:00+00:00'), $this->timingRow('200', '2026-10-17T11:52:00+00:00', 'recorded')]];
        $report['seasons'][0]['proposals'][0] += ['anchor_season' => '200', 'listed_steps' => 1,
            'anchor_day1_utc' => '2026-10-17T11:52:00+00:00', 'hypothetical_day1_utc' => '2026-10-17T10:00:00+00:00'];
        $input = (new EstimatedTimingInput)->read(json_encode($report));
        $this->assertCount(2, $input['seasons']);
        $report['seasons'][0]['proposals'][0]['listed_steps'] = 2;
        $this->expectException(RuntimeException::class);
        (new EstimatedTimingInput)->read(json_encode($report));
    }

    public function test_command_refuses_production_before_reading_any_report_or_database(): void
    {
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->artisan('strategy:replay-estimated-history', ['report' => '/does-not-exist'])
            ->expectsOutput('Use the isolated local mirror; production research replay is disabled.')->assertExitCode(1);
    }
}
