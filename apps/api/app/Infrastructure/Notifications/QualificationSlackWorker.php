<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use RuntimeException;

final class QualificationSlackWorker extends QualificationEmailWorker
{
    public function __construct(ChampionMonitoring $monitoring, PaperMarket $market, QualificationEmailPolicy $policy,
        QualificationSlackStore $store, QualificationSlackSettings $settings, private SlackTransport $transport, AlertStakePlanner $planner)
    {
        parent::__construct($monitoring, $market, $policy, $store, $settings, $planner);
    }

    protected function configured(): bool
    {
        return config('notifications.slack_qualification.enabled') && $this->transport->configured();
    }

    protected function send(array $details): void
    {
        $escape = fn ($value) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $value);
        $text = "KioTrad — qualified opportunity\n";
        foreach ($details as $label => $value) {
            $text .= $escape($label).': '.$escape($value)."\n";
        }
        $result = $this->transport->send(['text' => $text, 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false]);
        if ($result['status'] !== 'sent') {
            throw new RuntimeException('Slack submission not confirmed; no automatic resend.');
        }
    }

    protected function deliveryStatus(): string
    {
        return 'submitted_to_slack';
    }
}
