<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\AlertStakePlanner;
use App\Infrastructure\Notifications\QualificationEmailPolicy;
use App\Infrastructure\Notifications\QualificationSlackSettings;
use App\Infrastructure\Notifications\QualificationSlackStore;
use App\Infrastructure\Notifications\QualificationSlackWorker;
use App\Infrastructure\Notifications\SlackTransport;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class QualificationSlackTest extends TestCase
{
    private function planner(): AlertStakePlanner
    {
        $planner = Mockery::mock(AlertStakePlanner::class);
        $planner->shouldReceive('make')->andReturn(['status' => 'estimated', 'policy_version' => 'synthetic-test']);
        $planner->shouldReceive('details')->andReturn(['Planned total spend' => 'Synthetic test plan', 'Run capital' => '₦75,000.00 synthetic']);

        return $planner;
    }

    public function test_slack_uses_its_own_settings_transport_and_delivery_identity_without_smtp(): void
    {
        $this->app->instance('env', 'production');
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03T10:00:00Z'));
        config(['notifications.slack_qualification.enabled' => true, 'notifications.email.enabled' => false, 'mail.default' => 'log']);
        $settings = [...(new QualificationSlackSettings)->defaults(), 'capital' => 75000, 'next_win_qualification' => 3];
        $storeSettings = Mockery::mock(QualificationSlackSettings::class);
        $storeSettings->shouldReceive('read')->once()->andReturn($settings);
        $candidate = ['status' => 'watching_fixture', 'attempt' => 1, 'team_uid' => 'team:1', 'team' => 'Synthetic <!channel>',
            'competition' => 'virtual-euro', 'season_id' => '123', 'strategy_id' => 'champion-next-win', 'version' => 'test', 'trigger_day' => 3,
            'next_match' => ['scheduled_start_at' => '2026-11-03T10:03:00Z', 'matchday' => 4, 'home_team' => 'Synthetic', 'away_team' => 'Opponent']];
        $monitor = Mockery::mock(ChampionMonitoring::class);
        $monitor->shouldReceive('forEmail')->with($settings)->once()->andReturn([$candidate]);
        $market = Mockery::mock(PaperMarket::class);
        $market->shouldReceive('facts')->once()->andReturn(['virtual-euro:123:team:1:4' => ['collection_interrupted' => false,
            'observed_at' => now('UTC')->toIso8601String(), 'fixture' => ['scheduled_start_at' => $candidate['next_match']['scheduled_start_at']]]]);
        $store = Mockery::mock(QualificationSlackStore::class);
        $store->shouldReceive('claim')->once()->andReturn(true);
        $store->shouldReceive('finish')->once()->with(Mockery::type('string'), 'submitted_to_slack');
        $transport = Mockery::mock(SlackTransport::class);
        $transport->shouldReceive('configured')->once()->andReturn(true);
        $transport->shouldReceive('send')->once()->with(Mockery::on(fn ($payload) => str_contains($payload['text'], '₦75,000.00')
            && str_contains($payload['text'], '&lt;!channel&gt;') && ! str_contains($payload['text'], '<!channel>') && $payload['mrkdwn'] === false))->andReturn(['status' => 'sent']);
        $worker = new QualificationSlackWorker($monitor, $market, new QualificationEmailPolicy, $store, $storeSettings, $transport, $this->planner());
        $this->assertSame('submitted:1', $worker->tick());
    }

    public function test_slack_settings_are_authenticated_independently_and_do_not_expose_webhook(): void
    {
        config(['notifications.slack_qualification.settings_token' => str_repeat('s', 32), 'notifications.slack_qualification.enabled' => true,
            'notifications.webhook_url' => 'secret webhook']);
        $store = Mockery::mock(QualificationSlackSettings::class);
        $settings = [...(new QualificationSlackSettings)->defaults(), 'capital' => 90000];
        $store->shouldReceive('read')->once()->andReturn($settings);
        $store->shouldReceive('save')->once()->with($settings)->andReturn($settings);
        $this->app->instance(QualificationSlackSettings::class, $store);
        $this->getJson('/api/v1/notifications/slack-settings')->assertForbidden();
        $this->withToken(str_repeat('s', 32))->getJson('/api/v1/notifications/slack-settings')
            ->assertOk()->assertJsonPath('settings.capital', 90000)->assertDontSee('secret webhook');
        $this->withToken(str_repeat('s', 32))->putJson('/api/v1/notifications/slack-settings', $settings)
            ->assertOk()->assertJsonPath('settings.capital', 90000);
    }
}
