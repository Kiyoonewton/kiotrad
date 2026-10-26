<?php

namespace Tests\Feature;

use App\Domain\Strategies\SimulationDefinitionSnapshot;
use App\Infrastructure\Provider\ArchivedFeed;
use App\Infrastructure\Provider\RawEvidenceRebuilder;
use RuntimeException;
use Tests\TestCase;

class RawEvidenceTest extends TestCase
{
    public function test_raw_body_and_historical_timestamps_survive_decode(): void
    {
        $body = json_encode(['doc' => [['data' => ['matches' => [['time' => ['uts' => 1700000000]]]]]]]);
        $path = tempnam(sys_get_temp_dir(), 'raw');
        try {
            file_put_contents($path, gzencode(json_encode(['url' => 'https://example.invalid/fixtures',
                'requested_at' => '2026-10-24T00:00:00Z', 'received_at' => '2026-10-24T00:00:01Z',
                'http_status' => 200, 'body_sha256' => hash('sha256', $body), 'body_base64' => base64_encode($body)])));
            $record = (new ArchivedFeed)->read($path);
            $this->assertSame(1700000000, $record['feed']->document['data']['matches'][0]['time']['uts']);
            $this->assertSame('2026-10-24T00:00:01+00:00', $record['feed']->receivedAt->toIso8601String());
        } finally {
            unlink($path);
        }
    }

    public function test_corrupted_evidence_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'raw');
        try {
            file_put_contents($path, gzencode(json_encode(['body_sha256' => str_repeat('0', 64), 'body_base64' => base64_encode('{}')])));
            $this->expectException(RuntimeException::class);
            (new ArchivedFeed)->read($path);
        } finally {
            unlink($path);
        }
    }

    public function test_rebuild_refuses_production_database(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->expectException(RuntimeException::class);
        app(RawEvidenceRebuilder::class)->rebuild('/unused');
    }

    public function test_strategy_snapshot_rejects_changed_rules(): void
    {
        $service = new SimulationDefinitionSnapshot;
        $snapshot = $service->current();
        $service->verify($snapshot);
        $snapshot['strategies'][0]['rules']['max_attempts'] = 99;
        $this->expectException(RuntimeException::class);
        $service->verify($snapshot);
    }
}
