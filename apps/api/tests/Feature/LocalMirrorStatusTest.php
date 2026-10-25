<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LocalMirrorStatus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalMirrorStatusTest extends TestCase
{
    public function test_production_does_not_query_local_sync_metadata(): void
    {
        config(['kiotrad.local_data_mirror' => false]);
        DB::shouldReceive('connection')->never();
        $this->assertNull(app(LocalMirrorStatus::class)->view());
    }

    public function test_mirror_flag_rejects_a_production_database_before_querying(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad']);
        DB::shouldReceive('connection')->never();
        $this->expectException(\RuntimeException::class);
        app(LocalMirrorStatus::class)->view();
    }
}
