<?php

namespace App\Infrastructure\Notifications;

class QualificationSlackSettings extends QualificationEmailSettings
{
    protected function channel(): string
    {
        return 'slack';
    }

    protected function collectionName(): string
    {
        return 'qualification_slack_settings';
    }
}
