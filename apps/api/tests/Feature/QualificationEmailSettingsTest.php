<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\QualificationEmailPolicy;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use Mockery;
use Tests\TestCase;

class QualificationEmailSettingsTest extends TestCase
{
    public function test_settings_require_server_configured_token_before_reading_storage(): void
    {
        config(['notifications.email.settings_token' => str_repeat('a', 32)]);
        $store = Mockery::mock(QualificationEmailSettings::class);
        $store->shouldNotReceive('read');
        $store->shouldNotReceive('save');
        $this->app->instance(QualificationEmailSettings::class, $store);
        $this->getJson('/api/v1/notifications/email-settings')->assertForbidden();
        $this->withToken('wrong')->putJson('/api/v1/notifications/email-settings', [])->assertForbidden();
    }

    public function test_valid_settings_save_and_read_without_exposing_mail_credentials(): void
    {
        config(['notifications.email.settings_token' => str_repeat('a', 32), 'notifications.email.enabled' => false,
            'mail.mailers.smtp.password' => 'never expose this']);
        $settings = [...(new QualificationEmailSettings)->defaults(), 'capital' => 75000, 'next_season_qualification' => 7, 'next_win_attempts' => 6];
        $store = Mockery::mock(QualificationEmailSettings::class);
        $store->shouldReceive('save')->once()->with($settings)->andReturn($settings);
        $store->shouldReceive('read')->once()->andReturn($settings);
        $this->app->instance(QualificationEmailSettings::class, $store);
        $this->withToken(str_repeat('a', 32))->putJson('/api/v1/notifications/email-settings', $settings)
            ->assertOk()->assertJsonPath('settings.capital', 75000)->assertJsonPath('settings.next_win_attempts', 6)
            ->assertDontSee('never expose this');
        $this->withToken(str_repeat('a', 32))->getJson('/api/v1/notifications/email-settings')
            ->assertOk()->assertJsonPath('settings.next_season_qualification', 7)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_settings_are_not_saved(): void
    {
        config(['notifications.email.settings_token' => str_repeat('a', 32)]);
        $store = Mockery::mock(QualificationEmailSettings::class);
        $store->shouldNotReceive('save');
        $this->app->instance(QualificationEmailSettings::class, $store);
        $this->withToken(str_repeat('a', 32))->putJson('/api/v1/notifications/email-settings', [
            ...(new QualificationEmailSettings)->defaults(), 'capital' => 0, 'next_win_attempts' => 11, 'lead_seconds' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['capital', 'next_win_attempts', 'lead_seconds']);
    }

    public function test_email_policy_uses_custom_threshold_budget_strategy_and_lead(): void
    {
        $candidate = ['status' => 'watching_fixture', 'attempt' => 1, 'team_uid' => '1', 'team' => 'Synthetic',
            'strategy_id' => 'champion-next-win', 'competition' => 'virtual-euro', 'version' => 'test', 'season_id' => '123', 'trigger_day' => 3,
            'next_match' => ['scheduled_start_at' => '2026-11-03T10:03:00Z', 'home_team' => 'Synthetic', 'away_team' => 'Opponent', 'matchday' => 4]];
        $policy = new QualificationEmailPolicy;
        $now = '2026-11-03T10:00:00Z';
        $this->assertNull($policy->prepare($candidate, $now));
        $settings = [...(new QualificationEmailSettings)->defaults(), 'capital' => 75000, 'next_win_qualification' => 3, 'next_win_attempts' => 6];
        $alert = $policy->prepare($candidate, $now, $settings);
        $this->assertStringContainsString('₦75,000.00', $alert['details']['Reference capital']);
        $this->assertSame(6, $alert['details']['Attempts maximum']);
        $this->assertNull($policy->prepare($candidate, $now, [...$settings, 'next_win_enabled' => false]));
        $this->assertNull($policy->prepare($candidate, $now, [...$settings, 'league' => 'virtual-football']));
        $this->assertNull($policy->prepare($candidate, $now, [...$settings, 'lead_seconds' => 200]));
    }
}
