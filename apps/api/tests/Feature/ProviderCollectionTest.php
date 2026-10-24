<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\CollectorState;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCollectionTest extends TestCase
{
    public function test_invalid_league_never_contacts_provider_or_storage(): void
    {
        Http::fake();
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $this->artisan('provider:collect', ['competition' => 'unknown'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_reported_without_writing_checkpoint(): void
    {
        $state = $this->mock(CollectorState::class);
        $state->shouldReceive('read')->once()->with('virtual-football')->andReturn([]);
        $state->shouldReceive('write')->once();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->mock(CollectionStore::class)->shouldNotReceive('save');
        $this->artisan('provider:collect', ['competition' => 'virtual-football'])
            ->expectsOutputToContain('collection failed')->assertFailed();
    }
}
