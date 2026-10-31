<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LocalMirrorStatus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class LocalMirrorStatusTest extends TestCase
{
    public function test_production_does_not_query_local_sync_metadata(): void
    {
        config(['kiotrad.local_data_mirror' => false]);
        DB::shouldReceive('connection')->never();
        $this->assertNull(app(LocalMirrorStatus::class)->view());
    }

    public function test_local_metadata_identifies_the_active_snapshot_for_readiness_checks(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad_local_20261031T000000Z']);
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->with(['_id' => 'source'])->andReturn([
            'completed_at' => '2026-10-31T00:00:00Z', 'captures' => 42, 'mode' => 'raw_evidence_rebuild',
        ]);
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->once()->with('mongodb')->andReturn($connection);
        $connection->shouldReceive('getDatabase')->once()->andReturn((object) ['local_sync_status' => $collection]);
        $view = app(LocalMirrorStatus::class)->view();
        $this->assertSame('kiotrad_local_20261031T000000Z', $view['database']);
        $this->assertSame(42, $view['captures']);
    }

    public function test_mirror_flag_rejects_a_production_database_before_querying(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad']);
        DB::shouldReceive('connection')->never();
        $this->expectException(\RuntimeException::class);
        app(LocalMirrorStatus::class)->view();
    }
}
