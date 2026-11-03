<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Support\Facades\DB;

class QualificationEmailSettings
{
    public function defaults(): array
    {
        return ['enabled' => true, 'capital' => intdiv((int) config('notifications.email.capital_minor', 5000000), 100),
            'lead_seconds' => (int) config('notifications.email.lead_seconds', 10), 'league' => 'all',
            'next_win_enabled' => true, 'next_win_qualification' => 5, 'next_win_attempts' => 4,
            'next_season_enabled' => true, 'next_season_qualification' => 5, 'next_season_attempts' => 4];
    }

    public function read(): array
    {
        $row = DB::connection('mongodb')->getDatabase()->qualification_email_settings->findOne(['_id' => 'current'],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

        return array_replace($this->defaults(), array_intersect_key($row ?? [], $this->defaults()));
    }

    public function save(array $settings): array
    {
        DB::connection('mongodb')->getDatabase()->qualification_email_settings->updateOne(['_id' => 'current'],
            ['$set' => [...$settings, 'updated_at' => now('UTC')->toIso8601String()]], ['upsert' => true]);

        return $settings;
    }
}
