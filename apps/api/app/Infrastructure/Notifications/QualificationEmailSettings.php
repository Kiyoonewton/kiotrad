<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class QualificationEmailSettings
{
    protected function collectionName(): string
    {
        return 'qualification_email_settings';
    }

    public function defaults(): array
    {
        return ['enabled' => true, 'allowance' => 1, 'capital' => intdiv((int) config('notifications.email.capital_minor', 5000000), 100),
            'lead_seconds' => (int) config('notifications.email.lead_seconds', 10), 'league' => 'all',
            'next_win_enabled' => true, 'next_win_qualification' => 5, 'next_win_attempts' => 4,
            'next_season_enabled' => true, 'next_season_qualification' => 5, 'next_season_attempts' => 4];
    }

    protected function channel(): string
    {
        return 'email';
    }

    public function read(): array
    {
        $shared = app(QualificationAlertSettings::class)->stored();
        if ($shared !== null) {
            $settings = array_replace($this->defaults(), array_intersect_key($shared, $this->defaults()));
            $settings['enabled'] = $settings['enabled'] && ($shared[$this->channel().'_enabled'] ?? false);

            return $settings;
        }

        return $this->legacyRead();
    }

    public function legacyRead(): array
    {
        $row = DB::connection('mongodb')->getDatabase()->selectCollection($this->collectionName())->findOne(['_id' => 'current'],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        return array_replace($this->defaults(), array_intersect_key($row ?? [], $this->defaults()));
    }

    public function save(array $settings): array
    {
        if (app(QualificationAlertSettings::class)->stored() !== null) {
            $patch = array_diff_key($settings, ['enabled' => true]);
            $patch[$this->channel().'_enabled'] = $settings['enabled'];
            DB::connection('mongodb')->getDatabase()->qualification_alert_settings->updateOne(['_id' => 'current'],
                ['$set' => [...$patch, 'updated_at' => now('UTC')->toIso8601String()]]);

            return $this->read();
        }
        DB::connection('mongodb')->getDatabase()->selectCollection($this->collectionName())->updateOne(['_id' => 'current'],
            ['$set' => [...$settings, 'updated_at' => now('UTC')->toIso8601String()]], ['upsert' => true]);

        return $settings;
    }
}
