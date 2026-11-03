<?php

namespace App\Infrastructure\Notifications;

class QualificationSlackStore extends QualificationEmailStore
{
    protected function collectionName(): string
    {
        return 'qualification_slack_alerts';
    }
}
