<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\QualificationAlertSettings;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use Mockery;
use Tests\TestCase;

class UnifiedAlertSettingsTest extends TestCase
{
    public function test_different_profiles_require_review_and_preserve_each_profile_until_saved(): void
    {
        $email = [...(new QualificationEmailSettings)->defaults(), 'capital' => 50000, 'enabled' => false];
        $slack = [...$email, 'capital' => 100000, 'enabled' => true];
        $view = (new QualificationAlertSettings)->combine(null, $email, $slack);
        $this->assertTrue($view['needs_review']);
        $this->assertTrue($view['settings']['enabled']);
        $this->assertFalse($view['settings']['email_enabled']);
        $this->assertTrue($view['settings']['slack_enabled']);
        $this->assertSame(100000, $view['profiles']['slack']['capital']);
        $saved = [...$view['settings'], 'capital' => 150000];
        $this->assertFalse((new QualificationAlertSettings)->combine($saved, $email, $slack)['needs_review']);
    }

    public function test_shared_endpoint_validates_authentication_migration_and_single_save(): void
    {
        config(['notifications.alert_settings_token' => str_repeat('a', 32)]);
        $store = Mockery::mock(QualificationAlertSettings::class);
        $settings = [...(new QualificationEmailSettings)->defaults(), 'email_enabled' => false, 'slack_enabled' => true, 'capital' => 100000];
        $view = ['settings' => $settings, 'profiles' => [], 'needs_review' => true];
        $store->shouldReceive('view')->times(3)->andReturn($view);
        $store->shouldReceive('save')->once()->with($settings)->andReturn($settings);
        $this->app->instance(QualificationAlertSettings::class, $store);
        $this->getJson('/api/v1/notifications/alert-settings')->assertForbidden();
        $this->withToken(str_repeat('a', 32))->getJson('/api/v1/notifications/alert-settings')->assertOk()->assertJsonPath('needs_review', true);
        $this->withToken(str_repeat('a', 32))->putJson('/api/v1/notifications/alert-settings', $settings)->assertUnprocessable()->assertJsonValidationErrors('migration_source');
        $this->withToken(str_repeat('a', 32))->putJson('/api/v1/notifications/alert-settings', [...$settings, 'migration_source' => 'slack'])
            ->assertOk()->assertJsonPath('settings.capital', 100000)->assertJsonPath('needs_review', false);
    }
}
