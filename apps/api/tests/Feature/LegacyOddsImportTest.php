<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ArchivedFeed;
use App\Infrastructure\Provider\LegacyOddsImporter;
use App\Infrastructure\Provider\LegacyOddsSource;
use ArrayIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LegacyOddsImportTest extends TestCase
{
    use ProviderFixtures;

    private function sourceFile(): string
    {
        config(['database.connections.mongodb.database' => 'kiotrad', 'provider.competitions' => ['test' => ['tournament_uid' => 31867]]]);
        $path = tempnam(sys_get_temp_dir(), 'legacy');
        file_put_contents($path, json_encode(['queryUrl' => 'vfc_stats_round_odds2/vf:season:200/1', 'doc' => [$this->oddsFixture()]]));

        return $path;
    }

    private function fixtures(): array
    {
        $rows = [];
        foreach (range(99, 106) as $id) {
            $rows[] = ['provider_match_id' => 'vf:match:'.$id, 'matchday' => 1, 'home_team_uid' => '10', 'away_team_uid' => '20',
                'scheduled_start_at' => '2026-10-20T10:00:00Z', 'status' => 'settled', 'result' => 'H'];
        }

        $rows[] = [...$rows[0], 'provider_match_id' => 'vf:match:107', 'matchday' => 2,
            'scheduled_start_at' => '2026-10-20T10:03:40Z'];

        return $rows;
    }

    public function test_original_bytes_and_unknown_retrieval_time_survive_archival_decode(): void
    {
        $path = $this->sourceFile();
        $archive = tempnam(sys_get_temp_dir(), 'envelope');
        try {
            $source = app(LegacyOddsSource::class)->decode(file_get_contents($path), 'original.json', '2026-10-26T10:00:00Z');
            $envelope = app(LegacyOddsSource::class)->envelope($source);
            $this->assertNull($envelope['received_at']);
            $this->assertNull($envelope['requested_at']);
            $this->assertNull($envelope['http_status']);
            $this->assertSame(file_get_contents($path), base64_decode($envelope['body_base64']));
            file_put_contents($archive, gzencode(json_encode($envelope)));
            $record = app(ArchivedFeed::class)->read($archive);
            $this->assertSame('import_time_original_retrieval_unknown', $record['feed']->provenance['timestamp_basis']);
            $this->assertSame('2026-10-26T10:00:00+00:00', $record['feed']->receivedAt->toIso8601String());
            $this->assertNull($record['feed']->evidence()['received_at']);
            $this->assertNull($record['feed']->evidence()['http_status']);
        } finally {
            unlink($path);
            unlink($archive);
        }
    }

    public function test_dry_run_reports_missing_prices_without_writes(): void
    {
        $path = $this->sourceFile();
        try {
            $imports = Mockery::mock();
            $imports->shouldReceive('findOne')->once()->andReturn(null);
            $captures = Mockery::mock();
            $captures->shouldReceive('find')->once()->andReturn(new ArrayIterator([['matches' => $this->fixtures()]]));
            DB::shouldReceive('connection->getDatabase')->andReturn((object) ['legacy_odds_imports' => $imports, 'provider_captures' => $captures]);
            $result = app(LegacyOddsImporter::class)->import($path, false);
            $this->assertSame('recoverable', $result['status']);
            $this->assertSame(1, $result['missing_price_matches']);
        } finally {
            unlink($path);
        }
    }

    public function test_apply_is_insert_only_and_records_provenance_for_sync(): void
    {
        Storage::fake('local');
        $path = $this->sourceFile();
        try {
            $imports = Mockery::mock();
            $imports->shouldReceive('findOne')->once()->andReturn(null);
            $imports->shouldReceive('updateOne')->once()->with(Mockery::any(), Mockery::on(fn ($v) => $v['$setOnInsert']['provenance']['accepted_match_ids'] === ['vf:match:99']), ['upsert' => true]);
            $captures = Mockery::mock();
            $captures->shouldReceive('find')->once()->andReturn(new ArrayIterator([['matches' => $this->fixtures()]]));
            $captures->shouldReceive('updateOne')->once()->with(Mockery::any(), Mockery::on(function ($update) {
                $quote = $update['$setOnInsert']['odds_snapshots'][0];

                return count($update['$setOnInsert']['matches']) === 9 && $quote['prices']['H'] === '2.50' && ! $quote['pre_match_verified']
                    && $quote['provenance']['original_received_at'] === null;
            }), ['upsert' => true]);
            $checkpoint = Mockery::mock();
            $checkpoint->shouldReceive('updateOne')->once()->with(Mockery::any(), Mockery::any(), ['upsert' => true]);
            $checkpoint->shouldReceive('updateOne')->twice()->with(Mockery::any(), Mockery::any());
            $db = Mockery::mock();
            $db->legacy_odds_imports = $imports;
            $db->provider_captures = $captures;
            $db->season_checkpoints = $checkpoint;
            $db->shouldReceive('selectCollection')->with('provider_captures')->andReturn($captures);
            $db->shouldReceive('selectCollection')->with('season_checkpoints')->andReturn($checkpoint);
            DB::shouldReceive('connection->getDatabase')->andReturn($db);
            $result = app(LegacyOddsImporter::class)->import($path, true);
            $envelope = json_decode(gzdecode(Storage::disk('local')->get($result['raw_path'])), true);
            $this->assertSame(file_get_contents($path), base64_decode($envelope['body_base64']));
            $this->assertSame(['vf:match:99'], $envelope['provenance']['accepted_match_ids']);
        } finally {
            unlink($path);
        }
    }

    public function test_duplicate_source_skips_mutation(): void
    {
        $path = $this->sourceFile();
        try {
            $imports = Mockery::mock();
            $imports->shouldReceive('findOne')->once()->andReturn(['_id' => hash_file('sha256', $path)]);
            DB::shouldReceive('connection->getDatabase')->andReturn((object) ['legacy_odds_imports' => $imports]);
            $this->assertSame('already_imported', app(LegacyOddsImporter::class)->import($path, true)['status']);
        } finally {
            unlink($path);
        }
    }

    public function test_conflicting_prices_are_reported_without_being_overwritten(): void
    {
        $path = $this->sourceFile();
        try {
            $imports = Mockery::mock();
            $imports->shouldReceive('findOne')->andReturn(null);
            $captures = Mockery::mock();
            $captures->shouldReceive('find')->andReturn(new ArrayIterator([['matches' => $this->fixtures(),
                'odds_snapshots' => [['provider_match_id' => 'vf:match:99', 'prices' => ['H' => '1.5', 'D' => '3', 'A' => '4']]]]]));
            DB::shouldReceive('connection->getDatabase')->andReturn((object) ['legacy_odds_imports' => $imports, 'provider_captures' => $captures]);
            $result = app(LegacyOddsImporter::class)->import($path, false);
            $this->assertSame(0, $result['missing_price_matches']);
            $this->assertSame(['vf:match:99'], $result['conflicting_match_ids']);
        } finally {
            unlink($path);
        }
    }
}
