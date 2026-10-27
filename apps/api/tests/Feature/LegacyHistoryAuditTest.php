<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryComparison;
use App\Infrastructure\Provider\LegacyHistoryParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LegacyHistoryAuditTest extends TestCase
{
    private function payload(array $outcomes = []): string
    {
        config(['provider.competitions' => ['virtual-euro' => ['tournament_uid' => 14149]]]);

        return json_encode(['queryUrl' => 'vfc_stats_round_odds2/vf:season:10/1', 'doc' => [
            ['timestamp' => 1792796400000, 'data' => ['odds' => [[
                'id' => 'vf:match:99', 'tournament_id' => 'vf:tournament:14149',
                'teams' => ['home' => ['id' => 'sr:competitor:1', 'name' => 'Home'],
                    'away' => ['id' => 'sr:competitor:2', 'name' => 'Away']],
                'market' => [['id' => 1, 'status' => -3, 'outcome' => $outcomes]],
            ]]]],
        ]], JSON_THROW_ON_ERROR);
    }

    private function source(): array
    {
        return app(LegacyHistoryParser::class)->parse($this->payload([
            ['id' => '1', 'odds' => '2.00', 'result' => 1],
            ['id' => '2', 'odds' => '3.00', 'result' => 0],
            ['id' => '3', 'odds' => '4.00', 'result' => 0],
        ]), 'original.json');
    }

    public function test_parser_retains_outcome_without_inventing_time_or_score(): void
    {
        $source = $this->source();
        $match = $source['matches'][0];
        $this->assertSame('H', $match['result']);
        $this->assertSame('2.00', $match['prices']['H']);
        $this->assertNull($match['scheduled_start_at']);
        $this->assertNull($match['home_score']);
        $this->assertNull($source['original_received_at']);
        $this->assertSame('unknown', $source['timing_status']);
    }

    public function test_empty_market_is_partial_evidence_not_blockage_or_loss(): void
    {
        $source = app(LegacyHistoryParser::class)->parse($this->payload(), 'empty.json');
        $this->assertCount(1, $source['matches']);
        $this->assertSame([], $source['matches'][0]['prices']);
        $this->assertNull($source['matches'][0]['result']);
    }

    public function test_duplicate_outcomes_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        app(LegacyHistoryParser::class)->parse($this->payload([
            ['id' => '1', 'odds' => '2', 'result' => 1],
            ['id' => '1', 'odds' => '2', 'result' => 1],
        ]), 'duplicate.json');
    }

    public function test_database_comparison_flags_identity_result_and_price_differences(): void
    {
        $source = $this->source();
        $match = $source['matches'][0];
        $rows = app(LegacyHistoryComparison::class)->compare([$source], [[
            'competition' => 'virtual-euro', 'matches' => [[...$match, 'result' => 'A', 'season_id' => '11']],
            'odds_snapshots' => [['provider_match_id' => 'vf:match:99', 'prices' => ['H' => '3', 'D' => '4', 'A' => '5']]],
        ]]);
        $this->assertContains('result', $rows[0]['conflicts']);
        $this->assertContains('season_id', $rows[0]['conflicts']);
        $this->assertSame('different_recorded_price', $rows[0]['price_status']);
    }

    public function test_all_conflicting_source_versions_are_flagged(): void
    {
        $source = $this->source();
        $other = $source;
        $other['matches'][0]['result'] = 'A';
        $rows = app(LegacyHistoryComparison::class)->compare([$source, $other], []);
        $this->assertContains('result', $rows[0]['conflicts']);
        $this->assertContains('result', $rows[1]['conflicts']);
        $this->assertSame('new_fixture', $rows[0]['fixture_status']);
    }

    public function test_local_dry_run_reads_captures_without_any_writes(): void
    {
        $body = $this->payload();
        $hash = hash('sha256', $body);
        $folder = sys_get_temp_dir().'/history-dry-run-'.bin2hex(random_bytes(8));
        mkdir($folder.'/files', 0700, true);
        file_put_contents($folder.'/files/'.$hash.'.json', $body);
        file_put_contents($folder.'/plan.json', '{}');
        file_put_contents($folder.'/manifest.json', json_encode([
            'package_version' => 'legacy-history-package-1.0.0', 'plan_sha256' => hash('sha256', '{}'),
            'files' => [['file' => $hash.'.json', 'sha256' => $hash,
                'competition' => 'virtual-euro', 'season_id' => '10', 'matchday' => 1]],
        ], JSON_THROW_ON_ERROR));
        config(['kiotrad.local_data_mirror' => true,
            'database.connections.mongodb.database' => 'kiotrad_local_20261027T000000Z']);
        $captures = \Mockery::mock();
        $captures->shouldReceive('find')->once()->andReturn(new \ArrayIterator([]));
        DB::shouldReceive('connection->getDatabase')->once()->andReturn((object) ['provider_captures' => $captures]);
        try {
            $this->artisan('provider:audit-legacy-history', ['folder' => $folder])->assertSuccessful();
        } finally {
            unlink($folder.'/files/'.$hash.'.json');
            unlink($folder.'/manifest.json');
            unlink($folder.'/plan.json');
            rmdir($folder.'/files');
            rmdir($folder);
        }
    }

    public function test_audit_refuses_production_before_database_access(): void
    {
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:audit-legacy-history', ['folder' => '/not-read'])
            ->expectsOutput('This audit requires an isolated local data mirror.')->assertFailed();
    }
}
