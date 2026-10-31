<?php

namespace Tests\Feature;

use App\Infrastructure\Diagnostics\RawAuditSelection;
use RuntimeException;
use Tests\TestCase;

class RawAuditSelectionTest extends TestCase
{
    public function test_subset_requires_matching_database_complete_report_and_pinned_hashes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audit-selection-');
        $database = 'kiotrad_local_20261031T000000Z';
        $rows = [['type' => 'start', 'database' => $database],
            ['type' => 'rejection', 'file' => 'source.json.gz', 'compressed_sha256' => str_repeat('a', 64)],
            ['type' => 'summary', 'mode' => 'raw_document_revalidation', 'database' => $database, 'complete' => true,
                'database_writes' => false, 'counts' => ['rejected_now' => 1]]];
        try {
            file_put_contents($path, implode("\n", array_map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR), $rows)));
            $this->assertSame(['source.json.gz' => str_repeat('a', 64)], (new RawAuditSelection)->load($path, $database));
            $this->expectException(RuntimeException::class);
            (new RawAuditSelection)->load($path, 'kiotrad_local_20261030T000000Z');
        } finally {
            unlink($path);
        }
    }
}
