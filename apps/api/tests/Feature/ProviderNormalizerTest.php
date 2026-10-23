<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\FeedObservation;
use App\Infrastructure\Provider\FixtureNormalizer;
use App\Infrastructure\Provider\OddsNormalizer;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class ProviderNormalizerTest extends TestCase
{
    use ProviderFixtures;

    public function test_fixture_epoch_is_scheduled_utc_time_not_response_or_display_time(): void
    {
        $row = (new FixtureNormalizer)->normalize($this->seasonFixture(), '200', 31867)[0];
        $this->assertSame('2026-10-23T23:00:52+00:00', $row['scheduled_start_at']);
        $this->assertSame('vf:match:99', $row['provider_match_id']);
        $this->assertNull($row['actual_start_at']);
        $this->assertNull($row['result']);
    }

    public function test_nil_draw_requires_full_time_evidence_even_when_period_is_nt(): void
    {
        $data = $this->seasonFixture();
        $data['matches'][0]['result'] = ['home' => 0, 'away' => 0, 'period' => 'nt'];
        $this->assertNull((new FixtureNormalizer)->normalize($data, '200', 31867)[0]['result']);
        $data['matches'][0]['periods']['ft'] = ['home' => 0, 'away' => 0];
        $row = (new FixtureNormalizer)->normalize($data, '200', 31867)[0];
        $this->assertSame('D', $row['result']);
        $this->assertSame('settled', $row['status']);
    }

    public function test_missing_schedule_stays_unknown(): void
    {
        $data = $this->seasonFixture();
        unset($data['matches'][0]['time']);
        $row = (new FixtureNormalizer)->normalize($data, '200', 31867)[0];
        $this->assertNull($row['scheduled_start_at']);
        $this->assertNull($row['time_source']);
    }

    public function test_duplicate_fixtures_are_rejected(): void
    {
        $data = $this->seasonFixture();
        $data['matches'][] = $data['matches'][0];
        $this->expectException(RuntimeException::class);
        (new FixtureNormalizer)->normalize($data, '200', 31867);
    }

    public function test_odds_capture_must_precede_scheduled_kickoff_and_be_unsettled(): void
    {
        $fixtures = (new FixtureNormalizer)->normalize($this->seasonFixture(), '200', 31867);
        $document = $this->oddsFixture();
        $time = CarbonImmutable::parse('2026-10-23T23:00:00Z');
        $before = new FeedObservation('https://example.test/feed', $time, $time, $document, ['doc' => [$document]]);
        $row = (new OddsNormalizer)->normalize($before, $fixtures, 31867, 1)[0];
        $this->assertTrue($row['pre_match_verified']);
        $this->assertSame(['H' => '2.50', 'D' => '3.00', 'A' => '2.80'], $row['prices']);
        $this->assertSame(1792796400000, $row['provider_response_timestamp_ms']);

        $afterTime = $time->addMinutes(2);
        $after = new FeedObservation('https://example.test/feed', $time, $afterTime, $document, ['doc' => [$document]]);
        $this->assertFalse((new OddsNormalizer)->normalize($after, $fixtures, 31867, 1)[0]['pre_match_verified']);

        $document['data']['odds'][0]['market'][0]['outcome'][0]['result'] = 1;
        $resolved = new FeedObservation('https://example.test/feed', $time, $time, $document, ['doc' => [$document]]);
        $this->assertFalse((new OddsNormalizer)->normalize($resolved, $fixtures, 31867, 1)[0]['pre_match_verified']);
    }

    public function test_odds_for_another_league_are_rejected(): void
    {
        $fixtures = (new FixtureNormalizer)->normalize($this->seasonFixture(), '200', 31867);
        $document = $this->oddsFixture();
        $document['data']['odds'][0]['tournament_id'] = 'vf:tournament:34616';
        $time = CarbonImmutable::now('UTC');
        $observation = new FeedObservation('https://example.test/feed', $time, $time, $document, ['doc' => [$document]]);
        $this->expectException(RuntimeException::class);
        (new OddsNormalizer)->normalize($observation, $fixtures, 31867, 1);
    }
}
