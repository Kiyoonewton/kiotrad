<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\SportradarClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RawFeedArchiveTest extends TestCase
{
    public function test_successful_and_invalid_responses_are_preserved_before_normalization(): void
    {
        Storage::fake('local');
        config(['provider.archive_raw_responses' => true]);
        $body = '{"doc":[{"data":{"extra_future_field":42}}]}';
        Http::fakeSequence()->push($body, 200)->push("blocked\xff", 503);
        app(SportradarClient::class)->statistics('example');
        try {
            app(SportradarClient::class)->statistics('example');
            $this->fail('Expected invalid feed rejection.');
        } catch (RuntimeException) {
            // Rejected input must still be available for investigation.
        }
        $files = Storage::disk('local')->allFiles('provider-evidence/raw');
        $this->assertCount(2, $files);
        $records = array_map(fn ($p) => json_decode(gzdecode(Storage::disk('local')->get($p)), true, 512, JSON_THROW_ON_ERROR), $files);
        $bodies = array_map(fn ($r) => base64_decode($r['body_base64'], true), $records);
        $this->assertContains($body, $bodies);
        $this->assertContains("blocked\xff", $bodies);
        foreach ($records as $record) {
            $this->assertSame(hash('sha256', base64_decode($record['body_base64'])), $record['body_sha256']);
            $this->assertNotEmpty($record['requested_at']);
            $this->assertNotEmpty($record['received_at']);
        }
    }

    public function test_archive_write_failure_does_not_advance_collection_with_unarchived_data(): void
    {
        config(['provider.archive_raw_responses' => true]);
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);
        Http::fake(['*' => Http::response(['doc' => [['data' => []]]])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be archived');
        app(SportradarClient::class)->statistics('example');
    }
}
