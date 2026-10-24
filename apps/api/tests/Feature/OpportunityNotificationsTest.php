<?php

namespace Tests\Feature;

use App\Domain\Paper\WinQuote;
use App\Infrastructure\Notifications\NotificationOutbox;
use App\Infrastructure\Notifications\OpportunityNotifications;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Paper\PaperStore;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class OpportunityNotificationsTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function candidate(): array
    {
        return ['status' => 'watching_fixture', 'attempt' => 1, 'team_uid' => '1', 'team' => 'Synthetic',
            'competition' => 'virtual-football', 'season_id' => '200', 'strategy_id' => 'champion-next-win',
            'version' => '0.2.0-observation', 'trigger_day' => 4,
            'next_match' => ['matchday' => 5, 'home_team' => 'Synthetic', 'away_team' => 'Example',
                'home_team_uid' => '1', 'away_team_uid' => '2', 'provider_match_id' => 'vf:match:5',
                'scheduled_start_at' => '2026-10-24T10:05:00+00:00', 'status' => 'scheduled_or_unconfirmed']];
    }

    public function test_qualification_without_bankroll_says_stake_pending_and_does_not_mutate_paper_state(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:00:00Z');
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('all')->once()->andReturn([$this->candidate()]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->once()->andReturn([]);
        $paper = Mockery::mock(PaperStore::class);
        $paper->shouldReceive('read')->once()->andReturn(null);
        $outbox = Mockery::mock(NotificationOutbox::class);
        $outbox->shouldReceive('enqueue')->once()->withArgs(fn ($key, $type, $details, $expiry) => $type === 'qualified'
            && str_contains($details['Proposed stake'], 'Pending') && $details['Scheduled Lagos'] === '2026-10-24 11:05:00 WAT');
        (new OpportunityNotifications($monitor, $market, $paper, new WinQuote))->scan($outbox);
    }

    public function test_verified_quote_is_proposed_and_uses_actual_available_paper_capital(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:00:00Z');
        $candidate = $this->candidate();
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('all')->andReturn([$candidate]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->andReturn(['virtual-football:200:1:5' => ['fixture' => $candidate['next_match'],
            'collection_interrupted' => false, 'price' => ['provider_match_id' => 'vf:match:5', 'pre_match_verified' => true,
                'captured_at' => '2026-10-24T09:59:00+00:00', 'capture_id' => 'synthetic-price', 'prices' => ['H' => '1.5']]]]);
        $paper = Mockery::mock(PaperStore::class);
        $paper->shouldReceive('read')->andReturn(['cash_minor' => 700000, 'active_run' => null]);
        $outbox = Mockery::mock(NotificationOutbox::class);
        foreach (['qualified', 'quote'] as $type) {
            $outbox->shouldReceive('enqueue')->once()->withArgs(fn ($key, $actualType, $details, $expiry) => $actualType === $type
                && $details['Proposed stake'] === '₦200.00' && $details['Potential gross payout'] === '₦300.00'
                && $expiry === '2026-10-24T10:04:00+00:00');
        }
        (new OpportunityNotifications($monitor, $market, $paper, new WinQuote))->scan($outbox);
    }

    public function test_past_fixtures_and_later_attempts_do_not_generate_new_qualification_alerts(): void
    {
        CarbonImmutable::setTestNow('2026-10-24T10:10:00Z');
        $past = $this->candidate();
        $later = [...$past, 'attempt' => 2];
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('all')->andReturn([$past, $later]);
        $paper = Mockery::mock(PaperStore::class);
        $paper->shouldReceive('read')->andReturn(null);
        (new OpportunityNotifications($monitor, Mockery::mock(PaperMarket::class), $paper, new WinQuote))->scan(Mockery::mock(NotificationOutbox::class));
        $this->assertTrue(true);
    }
}
