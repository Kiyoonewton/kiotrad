<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\ArchivedFeed;
use App\Infrastructure\Provider\RawRebuildIndex;
use ArrayIterator;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RawRebuildIndexTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/raw-index-'.bin2hex(random_bytes(8));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->folder);
        parent::tearDown();
    }

    private function write(string $name, string $route, string $received = '2026-10-26T10:00:00Z'): void
    {
        $body = json_encode(['doc' => [['data' => []]]]);
        file_put_contents($this->folder.'/'.$name.'.json.gz', gzencode(json_encode([
            'url' => 'https://example.invalid/'.$route, 'requested_at' => $received, 'received_at' => $received,
            'http_status' => 200, 'body_sha256' => hash('sha256', $body), 'body_base64' => base64_encode($body),
        ])));
    }

    private function db(array $rows = [], ?array $state = null): object
    {
        $files = Mockery::mock();
        $files->shouldReceive('find')->once()->andReturn(new ArrayIterator($rows));
        $metadata = Mockery::mock();
        $metadata->shouldReceive('findOne')->once()->andReturn($state);

        return (object) ['local_raw_files' => $files, 'local_raw_rebuild' => $metadata];
    }

    public function test_new_odds_replays_its_older_fixture_but_not_untouched_seasons(): void
    {
        $index = new RawRebuildIndex;
        $this->write('one', 'stats_season_fixtures2/100');
        $this->write('two', 'stats_season_fixtures2/200');
        $initial = $index->prepare($this->folder, $this->db(), new ArchivedFeed, null);
        $this->write('three', 'vfc_stats_round_odds2/vf:season:100/1');
        $state = ['version' => $index->version(), 'complete' => true];
        $delta = $index->prepare($this->folder, $this->db(array_values($initial['rows']), $state), new ArchivedFeed, null);
        $this->assertTrue($delta['incremental']);
        $this->assertSame(1, $delta['new_files']);
        $this->assertCount(2, $delta['selected']);
        $this->assertSame(['fixtures', 'odds'], array_column($delta['selected'], 'phase'));
        $same = $index->prepare($this->folder, $this->db(array_values($delta['rows']), $state), new ArchivedFeed, null);
        $this->assertSame(0, $same['new_files']);
        $this->assertSame([], $same['selected']);
    }

    public function test_changed_bytes_are_reprocessed_and_progress_is_reported(): void
    {
        $index = new RawRebuildIndex;
        $this->write('one', 'stats_season_fixtures2/100');
        $initial = $index->prepare($this->folder, $this->db(), new ArchivedFeed, null);
        $this->write('one', 'stats_season_fixtures2/100', '2026-10-27T10:00:00Z');
        $progress = [];
        $plan = $index->prepare($this->folder, $this->db(array_values($initial['rows']), ['version' => $index->version(), 'complete' => true]),
            new ArchivedFeed, function (...$values) use (&$progress): void {
                $progress[] = $values;
            });
        $this->assertSame(1, $plan['new_files']);
        $this->assertSame([['index', 1, 1]], $progress);
    }

    public function test_missing_previous_evidence_fails_instead_of_silently_keeping_stale_views(): void
    {
        $this->expectException(RuntimeException::class);
        $index = new RawRebuildIndex;
        $index->prepare($this->folder, $this->db([['_id' => 'missing', 'hash' => 'x']], ['version' => $index->version(), 'complete' => true]), new ArchivedFeed, null);
    }

    public function test_changed_parser_version_cannot_be_mixed_with_seeded_views(): void
    {
        $this->expectException(RuntimeException::class);
        $metadata = Mockery::mock();
        $metadata->shouldReceive('findOne')->once()->andReturn(['version' => 'old', 'complete' => true]);
        (new RawRebuildIndex)->prepare($this->folder, (object) ['local_raw_rebuild' => $metadata], new ArchivedFeed, null);
    }

    public function test_interrupted_staging_database_cannot_be_treated_as_completed_seed(): void
    {
        $this->expectException(RuntimeException::class);
        $index = new RawRebuildIndex;
        $metadata = Mockery::mock();
        $metadata->shouldReceive('findOne')->once()->andReturn(['version' => $index->version(), 'complete' => false]);
        $index->prepare($this->folder, (object) ['local_raw_rebuild' => $metadata], new ArchivedFeed, null);
    }
}
