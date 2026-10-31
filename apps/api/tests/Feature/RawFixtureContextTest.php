<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\RawFixtureContext;
use Mockery;
use Tests\TestCase;

class RawFixtureContextTest extends TestCase
{
    public function test_latest_capture_must_cover_all_requested_match_ids(): void
    {
        $collection = Mockery::mock();
        $old = ['_id' => 'older-covering', 'matches' => [['provider_match_id' => 'vf:match:1'], ['provider_match_id' => 'vf:match:2']]];
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => $q['scope'] === 'raw_rebuild' && $q['observed_at']['$lte'] === '2026-10-31T00:00:00Z'
            && $q['matches.provider_match_id']['$all'] === ['vf:match:1', 'vf:match:2']), Mockery::any())->andReturn($old);
        $selected = (new RawFixtureContext)->find($collection, ['scope' => 'raw_rebuild'], '2026-10-31T00:00:00Z', ['vf:match:1', 'vf:match:2']);
        $this->assertSame($old, $selected['capture']);
        $this->assertTrue($selected['earlier']);
    }

    public function test_later_covering_capture_is_explicitly_identified_as_later_context(): void
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => isset($q['observed_at'])), Mockery::any())->andReturnNull();
        $collection->shouldReceive('findOne')->once()->with(Mockery::on(fn ($q) => ! isset($q['observed_at'])
            && $q['matches.provider_match_id']['$all'] === ['vf:match:1']), Mockery::any())->andReturn(['_id' => 'later']);
        $this->assertFalse((new RawFixtureContext)->find($collection, [], '2026-10-31T00:00:00Z', ['vf:match:1'])['earlier']);
    }

    public function test_no_covering_capture_never_falls_back_to_an_incomplete_one(): void
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('findOne')->twice()->with(Mockery::on(fn ($q) => $q['matches.provider_match_id']['$all'] === ['vf:match:1']), Mockery::any())->andReturnNull();
        $this->assertNull((new RawFixtureContext)->find($collection, [], '2026-10-31T00:00:00Z', ['vf:match:1']));
    }
}
