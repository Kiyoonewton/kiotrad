<?php

namespace Tests\Feature;

use App\Infrastructure\Diagnostics\RawDocumentAudit;
use ArrayIterator;
use Mockery;
use Tests\TestCase;

class RawDocumentAuditTest extends TestCase
{
    public function test_audit_reports_a_hashed_rejection_without_database_writes_or_source_bodies(): void
    {
        $root = sys_get_temp_dir().'/raw-audit-'.bin2hex(random_bytes(8));
        mkdir($root);
        $body = json_encode(['doc' => [['data' => ['seasons' => [['_id' => 10, '_utid' => 14149, 'current' => false]]]]]], JSON_THROW_ON_ERROR);
        $path = $root.'/source.json.gz';
        file_put_contents($path, gzencode(json_encode(['url' => 'https://example.invalid/uniquetournament_seasons/14149?secret=hidden',
            'requested_at' => '2026-10-31T00:00:00Z', 'received_at' => '2026-10-31T00:00:00Z', 'http_status' => 200,
            'body_sha256' => hash('sha256', $body), 'body_base64' => base64_encode($body)], JSON_THROW_ON_ERROR)));
        $row = ['_id' => 'source.json.gz', 'hash' => hash_file('sha256', $path), 'phase' => 'catalogs', 'season' => null, 'at' => '2026-10-31T00:00:00Z'];
        $state = Mockery::mock();
        $state->shouldReceive('findOne')->once()->andReturn(['complete' => true, 'version' => 'synthetic']);
        $files = Mockery::mock();
        $files->shouldReceive('countDocuments')->once()->andReturn(1);
        $files->shouldReceive('find')->once()->andReturn(new ArrayIterator([$row]));
        $report = [];
        try {
            $summary = app(RawDocumentAudit::class)->run((object) ['local_raw_rebuild' => $state, 'local_raw_files' => $files], $root,
                function ($row) use (&$report): void {
                    $report[] = $row;
                }, fn () => null);
            $this->assertSame(1, $summary['counts']['rejected_now']);
            $this->assertSame('Archived catalog current season is ambiguous.', $report[0]['reason']);
            $this->assertSame($row['hash'], $report[0]['compressed_sha256']);
            $this->assertFalse($summary['database_writes']);
            $this->assertFalse($summary['historical_rejection_count_reconstructed']);
            $this->assertStringNotContainsString('hidden', json_encode($report));
            $this->assertStringNotContainsString('base64', json_encode($report));
            $this->assertSame($row['hash'], hash_file('sha256', $path));
        } finally {
            unlink($path);
            rmdir($root);
        }
    }
}
