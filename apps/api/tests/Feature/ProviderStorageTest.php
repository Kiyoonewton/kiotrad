<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\MongoCollectionStore;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderStorageTest extends TestCase
{
    public function test_capture_is_insert_only_and_checkpoint_is_guarded_against_older_retries(): void
    {
        $report = $this->report();
        $captures = Mockery::mock();
        $checkpoints = Mockery::mock();
        $database = Mockery::mock();
        DB::shouldReceive('connection->getDatabase')->twice()->andReturn($database);
        $database->shouldReceive('selectCollection')->with('provider_captures')->twice()->andReturn($captures);
        $database->shouldReceive('selectCollection')->with('provider_checkpoints')->twice()->andReturn($checkpoints);
        $captures->shouldReceive('updateOne')->twice()->withArgs(function ($filter, $update, $options) {
            $this->assertArrayHasKey('_id', $filter);
            $this->assertArrayHasKey('$setOnInsert', $update);
            $this->assertArrayNotHasKey('observations', $update['$setOnInsert']);
            $this->assertTrue($options['upsert']);

            return true;
        });
        $checkpoints->shouldReceive('updateOne')->twice()->with(
            ['_id' => 'virtual-football'],
            ['$setOnInsert' => ['observed_at' => '', 'capture_id' => null]], ['upsert' => true],
        );
        $checkpoints->shouldReceive('updateOne')->twice()->withArgs(function ($filter, $update) use ($report) {
            $this->assertSame(['$lte' => $report['observed_at']], $filter['observed_at']);
            $this->assertSame('200', $update['$set']['season_id']);

            return true;
        });
        $store = new MongoCollectionStore;
        $this->assertSame($store->save($report), $store->save($report));
    }

    public function test_failed_capture_write_never_touches_checkpoint(): void
    {
        $captures = Mockery::mock();
        $database = Mockery::mock();
        DB::shouldReceive('connection->getDatabase')->once()->andReturn($database);
        $database->shouldReceive('selectCollection')->with('provider_captures')->once()->andReturn($captures);
        $database->shouldNotReceive('selectCollection')->with('provider_checkpoints');
        $captures->shouldReceive('updateOne')->once()->andThrow(new RuntimeException('Storage unavailable'));
        $this->expectException(RuntimeException::class);
        (new MongoCollectionStore)->save($this->report());
    }

    private function report(): array
    {
        return [
            'competition' => 'virtual-football', 'season_id' => '200',
            'observed_at' => '2026-10-24T00:00:00+00:00',
            'matches' => [], 'odds_snapshots' => [],
            'observations' => [['payload' => ['private' => true]]],
        ];
    }
}
