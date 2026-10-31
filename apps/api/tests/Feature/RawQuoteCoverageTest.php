<?php

namespace Tests\Feature;

use App\Infrastructure\Diagnostics\RawQuoteCoverage;
use ArrayIterator;
use Mockery;
use Tests\TestCase;

class RawQuoteCoverageTest extends TestCase
{
    public function test_prices_are_reported_as_matching_different_or_missing_without_writes(): void
    {
        $captures = Mockery::mock();
        $captures->shouldReceive('find')->once()->andReturn(new ArrayIterator([['odds_snapshots' => [
            ['provider_match_id' => 'one', 'prices' => ['H' => '2.50', 'D' => '3.0', 'A' => '4.00']],
            ['provider_match_id' => 'two', 'prices' => ['H' => '2.80', 'D' => '3', 'A' => '4']],
        ]]]));
        $prices = array_map(fn ($id) => ['provider_match_id' => $id, 'prices' => ['H' => '2.5', 'D' => '3', 'A' => '4']], ['one', 'two', 'three']);
        $this->assertSame(['source_prices' => 3, 'matching_existing' => 1, 'different_existing' => 1, 'missing' => 1],
            (new RawQuoteCoverage)->inspect((object) ['provider_captures' => $captures], 'test', '200', $prices));
    }
}
