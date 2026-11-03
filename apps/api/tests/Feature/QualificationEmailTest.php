<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\AlertStakePlanner;
use App\Infrastructure\Notifications\QualificationEmail;
use App\Infrastructure\Notifications\QualificationEmailPolicy;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use App\Infrastructure\Notifications\QualificationEmailStore;
use App\Infrastructure\Notifications\QualificationEmailWorker;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class QualificationEmailTest extends TestCase
{
    private function settings(): QualificationEmailSettings
    {
        $store = Mockery::mock(QualificationEmailSettings::class);
        $store->shouldReceive('read')->andReturn((new QualificationEmailSettings)->defaults());

        return $store;
    }

    private function candidate(): array
    {
        return ['status' => 'watching_fixture', 'attempt' => 1, 'team_uid' => 'team:1', 'team' => 'Synthetic team',
            'competition' => 'virtual-euro', 'season_id' => '123', 'strategy_id' => 'champion-next-win', 'version' => 'test',
            'trigger_day' => 5, 'next_match' => ['scheduled_start_at' => '2026-11-03T10:03:00+00:00',
                'matchday' => 6, 'home_team' => 'Synthetic team', 'away_team' => 'Synthetic opponent']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03T10:00:00Z'));
        config(['notifications.email.enabled' => true, 'notifications.email.to' => 'recipient@example.com',
            'notifications.email.capital_minor' => 5000000, 'notifications.email.lead_seconds' => 10,
            'mail.default' => 'smtp', 'mail.from.address' => 'sender@example.com', 'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.username' => 'synthetic', 'mail.mailers.smtp.password' => 'synthetic']);
        Mail::fake();
    }

    private function planner(): AlertStakePlanner
    {
        $planner = Mockery::mock(AlertStakePlanner::class);
        $planner->shouldReceive('make')->andReturn(['status' => 'estimated', 'policy_version' => 'synthetic-test']);
        $planner->shouldReceive('details')->andReturn(['Planned total spend' => 'Synthetic test plan']);

        return $planner;
    }

    public function test_alert_has_fifty_thousand_total_budget_and_scheduled_lagos_time_without_live_stake(): void
    {
        $policy = new QualificationEmailPolicy;
        $alert = $policy->prepare($this->candidate(), now('UTC')->toIso8601String());
        $this->assertStringContainsString('₦50,000.00', $alert['details']['Reference capital']);
        $this->assertSame('2026-11-03 11:03:00', $alert['details']['Scheduled WAT']);
        $this->assertStringContainsString('Not calculated', $alert['details']['Stake']);
        $this->assertSame($alert['id'], $policy->prepare($this->candidate(), now('UTC')->addSecond()->toIso8601String())['id']);
        $this->assertNull($policy->prepare($this->candidate(), '2026-11-03T10:02:50Z'));
        $this->assertNull($policy->prepare([...$this->candidate(), 'status' => 'not_qualified'], now('UTC')->toIso8601String()));
        $this->assertNull($policy->prepare([...$this->candidate(), 'attempt' => 2], now('UTC')->toIso8601String()));
    }

    private function worker(bool $claim = true, bool $interrupted = false, string $observed = '2026-11-03T10:00:00+00:00'): QualificationEmailWorker
    {
        $candidate = $this->candidate();
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('forEmail')->with(Mockery::type('array'))->once()->andReturn([$candidate]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->once()->andReturn(['virtual-euro:123:team:1:6' => [
            'collection_interrupted' => $interrupted, 'observed_at' => $observed,
            'fixture' => ['scheduled_start_at' => $candidate['next_match']['scheduled_start_at']],
        ]]);
        $store = Mockery::mock(QualificationEmailStore::class);
        if (! $interrupted && $observed === '2026-11-03T10:00:00+00:00') {
            $store->shouldReceive('claim')->once()->andReturn($claim);
            if ($claim) {
                $store->shouldReceive('finish')->once()->with(Mockery::type('string'), 'submitted_to_smtp');
            }
        } else {
            $store->shouldNotReceive('claim');
        }

        return new QualificationEmailWorker($monitor, $market, new QualificationEmailPolicy, $store, $this->settings(), $this->planner());
    }

    public function test_sends_qualified_mail_after_fresh_fixture_check(): void
    {
        $this->assertSame('submitted:1', $this->worker()->tick());
        Mail::assertSent(QualificationEmail::class, fn ($mail) => $mail->hasTo('recipient@example.com'));
    }

    public function test_duplicate_alert_is_not_resent(): void
    {
        $this->assertSame('waiting', $this->worker(false)->tick());
        Mail::assertNothingSent();
    }

    public function test_interrupted_or_stale_collection_does_not_send(): void
    {
        $this->assertSame('waiting', $this->worker(true, true)->tick());
        $this->assertSame('waiting', $this->worker(true, false, '2026-11-03T09:58:00+00:00')->tick());
        Mail::assertNothingSent();
    }

    public function test_missing_mail_configuration_does_not_read_or_send_opportunities(): void
    {
        config(['mail.default' => 'log']);
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldNotReceive('forEmail');
        $worker = new QualificationEmailWorker($monitor, Mockery::mock(PaperMarket::class), new QualificationEmailPolicy, Mockery::mock(QualificationEmailStore::class), $this->settings(), $this->planner());
        $this->assertSame('disabled_or_unconfigured', $worker->tick());
        Mail::assertNothingSent();
    }

    public function test_ambiguous_smtp_failure_is_marked_uncertain_without_exposing_exception(): void
    {
        $candidate = $this->candidate();
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('forEmail')->andReturn([$candidate]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->andReturn(['virtual-euro:123:team:1:6' => [
            'collection_interrupted' => false, 'observed_at' => now('UTC')->toIso8601String(),
            'fixture' => ['scheduled_start_at' => $candidate['next_match']['scheduled_start_at']],
        ]]);
        $store = Mockery::mock(QualificationEmailStore::class);
        $store->shouldReceive('claim')->once()->andReturn(true);
        $store->shouldReceive('finish')->once()->with(Mockery::type('string'), 'delivery_uncertain');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('synthetic secret must not escape'));
        $worker = new QualificationEmailWorker($monitor, $market, new QualificationEmailPolicy, $store, $this->settings(), $this->planner());
        $this->assertSame('delivery_uncertain:1', $worker->tick());
    }

    public function test_missing_stake_inputs_are_retried_without_claiming_or_sending(): void
    {
        $candidate = $this->candidate();
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('forEmail')->once()->andReturn([$candidate]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->once()->andReturn(['virtual-euro:123:team:1:6' => [
            'collection_interrupted' => false, 'observed_at' => now('UTC')->toIso8601String(),
            'fixture' => ['scheduled_start_at' => $candidate['next_match']['scheduled_start_at']],
        ]]);
        $planner = Mockery::mock(AlertStakePlanner::class);
        $planner->shouldReceive('make')->once()->andReturn(['status' => 'missing_schedule_or_historical_odds']);
        $planner->shouldNotReceive('details');
        $store = Mockery::mock(QualificationEmailStore::class);
        $store->shouldNotReceive('claim');
        $worker = new QualificationEmailWorker($monitor, $market, new QualificationEmailPolicy, $store, $this->settings(), $planner);
        $this->assertSame('waiting_for_stake_inputs:1', $worker->tick());
        Mail::assertNothingSent();
    }
}
