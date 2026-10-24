<?php

namespace App\Infrastructure\Notifications;

use InvalidArgumentException;

final class SlackMessage
{
    public const TYPES = [
        'qualified' => ['🟢 QUALIFIED SEASON — Opportunity Detected', '#2E8B57'],
        'recovered_simulation' => ['🔵 RECOVERED SIMULATION — Historical Update', '#3B82F6'],
        'quote' => ['🔵 PAPER STAKE — Proposed', '#3B82F6'],
        'stake' => ['🔵 PAPER STAKE — Recorded', '#2563EB'],
        'settled' => ['🟣 PAPER RUN — Settled', '#8B5CF6'],
        'interrupted' => ['🟠 COLLECTION — Interrupted', '#F97316'],
        'pending' => ['🟡 DATA RECOVERY — Pending', '#EAB308'],
        'resumed' => ['🔵 COLLECTION — Resumed', '#3B82F6'],
        'recovered' => ['🟢 DATA RECOVERY — Queue Complete', '#2E8B57'],
        'season_recovered' => ['🟢 SEASON RECOVERY — Verified Complete', '#2E8B57'],
        'unknown_market' => ['🟠 MARKET — Availability Unknown', '#F59E0B'],
        'blocked_market' => ['🔴 MARKET BLOCK — Operator Confirmed', '#DC2626'],
        'storage' => ['🟠 STORAGE — Capacity Alert', '#F97316'],
        'test' => ['🔵 KIOTRAD — Notification Test', '#3B82F6'],
    ];

    public function make(string $type, array $details, string $id, string $now): array
    {
        [$title, $color] = self::TYPES[$type] ?? throw new InvalidArgumentException('Unknown alert type.');
        $blocks = [['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $title]]];
        foreach (array_chunk($details, 8, true) as $group) {
            $fields = [];
            foreach ($group as $label => $value) {
                $fields[] = ['type' => 'plain_text', 'text' => mb_substr($label.': '.(string) $value, 0, 500)];
            }
            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'plain_text',
            'text' => 'Event '.substr($id, 0, 16).' · recorded '.$now.' · paper simulation only']]];

        return ['text' => $title, 'attachments' => [['color' => $color, 'blocks' => $blocks]],
            'unfurl_links' => false, 'unfurl_media' => false];
    }
}
