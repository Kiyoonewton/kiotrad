<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RecoverSeasonLinksTest extends TestCase
{
    private function catalog(?int $secondStart = 200): void
    {
        config(['provider.archive_raw_responses' => false]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['doc' => [['data' => ['seasons' => [
            ['_id' => '900', '_utid' => 31867, 'current' => true, 'start' => ['uts' => $secondStart]],
            ['_id' => '100', '_utid' => 31867, 'start' => ['uts' => 100]],
        ]]]]])]);
    }

    public function test_catalog_recovery_writes_provider_adjacency_without_updating_collector_state(): void
    {
        $this->catalog();
        $collection = Mockery::mock();
        $db = (object) ['provider_catalog_links' => $collection];
        DB::shouldReceive('connection->getDatabase')->once()->andReturn($db);
        $collection->shouldReceive('bulkWrite')->once()
            ->with(Mockery::on(function ($operations) {
                $entry = $operations[0]['updateOne'];

                return count($operations) === 1 && $entry[0]['_id'] === 'virtual-football:100:900'
                    && $entry[1]['$setOnInsert']['continuity_status'] === 'provider_catalog_adjacency'
                    && $entry[2]['upsert'] === true;
            }), ['ordered' => false]);
        $this->artisan('provider:recover-season-links', ['competition' => 'virtual-football'])
            ->expectsOutputToContain('1 provider-confirmed links saved')->assertSuccessful();
    }

    public function test_ambiguous_catalog_does_not_write_links(): void
    {
        $this->catalog(100);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:recover-season-links', ['competition' => 'virtual-football'])->assertFailed();
    }

    public function test_unknown_league_does_not_contact_provider(): void
    {
        Http::fake();
        $this->artisan('provider:recover-season-links', ['competition' => 'unknown'])->assertFailed();
        Http::assertNothingSent();
    }
}
