<?php

namespace Tests\Feature;

use App\Infrastructure\Diagnostics\RawDocumentValidator;
use App\Infrastructure\Provider\FeedObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RawDocumentValidatorTest extends TestCase
{
    private function feed(string $route, array $data): FeedObservation
    {
        $at = CarbonImmutable::parse('2026-10-31T00:00:00Z');

        return new FeedObservation('https://example.invalid/'.$route, $at, $at, ['data' => $data], []);
    }

    public function test_catalog_conflicts_and_ambiguity_have_explicit_reasons(): void
    {
        $validator = app(RawDocumentValidator::class);
        foreach ([['seasons' => [['_utid' => 14149, '_id' => '10', 'current' => false]]],
            ['seasons' => [['_utid' => 14149, '_id' => '10', 'current' => true], ['_utid' => 34616, '_id' => '11']]]] as $data) {
            try {
                $validator->validate(new \stdClass, $this->feed('uniquetournament_seasons/14149', $data), 'catalogs');
                $this->fail('Invalid catalog accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('Archived catalog', $exception->getMessage());
            }
        }
    }

    public function test_missing_fixture_context_is_separate_from_invalid_odds(): void
    {
        $captures = Mockery::mock();
        $captures->shouldReceive('findOne')->twice()->andReturnNull();
        $feed = $this->feed('vfc_stats_round_odds2/vf:season:10/1', ['odds' => [['tournament_id' => 'vf:tournament:14149']]]);
        $this->assertSame('missing_fixture_context', app(RawDocumentValidator::class)->validate((object) ['provider_captures' => $captures], $feed, 'odds'));
    }

    public function test_odds_identity_mismatch_is_detected_using_read_only_fixture_queries(): void
    {
        $captures = Mockery::mock();
        $captures->shouldReceive('findOne')->once()->andReturn(['matches' => []]);
        $feed = $this->feed('vfc_stats_round_odds2/vf:season:10/1', ['odds' => [['id' => 'vf:match:99', 'tournament_id' => 'vf:tournament:14149']]]);
        $this->expectExceptionMessage('Odds cannot be joined unambiguously');
        app(RawDocumentValidator::class)->validate((object) ['provider_captures' => $captures], $feed, 'odds');
    }

    public function test_production_command_guard_does_not_read_database(): void
    {
        config(['kiotrad.local_data_mirror' => false]);
        DB::shouldReceive('connection')->never();
        $this->artisan('provider:audit-raw-documents', ['folder' => '/missing', '--report' => '/missing'])
            ->assertFailed();
    }
}
