<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class QualificationAlertSettings
{
    public function stored(): ?array
    {
        return DB::connection('mongodb')->getDatabase()->qualification_alert_settings->findOne(['_id' => 'current'],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    public function view(): array
    {
        return $this->combine($this->stored(), app(QualificationEmailSettings::class)->legacyRead(), app(QualificationSlackSettings::class)->legacyRead());
    }

    public function combine(?array $saved, array $email, array $slack): array
    {
        $defaults = [...$email, 'enabled' => $email['enabled'] || $slack['enabled'], 'email_enabled' => $email['enabled'], 'slack_enabled' => $slack['enabled']];
        $common = fn ($row) => array_diff_key($row, ['enabled' => true]);

        return ['settings' => $saved === null ? $defaults : array_replace($defaults, array_intersect_key($saved, $defaults)),
            'profiles' => ['email' => $email, 'slack' => $slack],
            'needs_review' => $saved === null && $common($email) != $common($slack)];
    }

    public function save(array $settings): array
    {
        DB::connection('mongodb')->getDatabase()->qualification_alert_settings->updateOne(['_id' => 'current'],
            ['$set' => [...$settings, 'updated_at' => now('UTC')->toIso8601String()]], ['upsert' => true]);

        return $settings;
    }
}
