<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryArchive;
use RuntimeException;
use Tests\TestCase;

class LegacyHistoryArchiveTest extends TestCase
{
    public function test_exact_bytes_are_preserved_and_existing_archive_is_never_rewritten(): void
    {
        $root = sys_get_temp_dir().'/history-raw-'.bin2hex(random_bytes(8));
        mkdir($root);
        $body = "{\n\"queryUrl\":\"vfc_stats_round_odds2/vf:season:10/1\"\n}";
        $source = tempnam(sys_get_temp_dir(), 'history-source');
        file_put_contents($source, $body);
        $hash = hash('sha256', $body);
        $file = ['path' => $source, 'sha256' => $hash, 'source_filename' => 'original.json'];
        $path = $root.'/'.$hash.'.json.gz';
        try {
            $archive = app(LegacyHistoryArchive::class);
            $this->assertSame([$hash], $archive->preserve($root, [$file], '2026-10-27T12:00:00Z'));
            $before = file_get_contents($path);
            $record = json_decode(gzdecode($before), true);
            $this->assertSame($body, base64_decode($record['body_base64']));
            $this->assertNull($record['received_at']);
            $this->assertNull($record['http_status']);
            $archive->preserve($root, [$file], '2026-10-28T12:00:00Z');
            $this->assertSame($before, file_get_contents($path));
            file_put_contents($path, gzencode(json_encode(['body_base64' => base64_encode('changed'), 'body_sha256' => $hash])));
            $this->expectException(RuntimeException::class);
            $archive->preserve($root, [$file], '2026-10-28T12:00:00Z');
        } finally {
            @unlink($path);
            unlink($source);
            rmdir($root);
        }
    }
}
