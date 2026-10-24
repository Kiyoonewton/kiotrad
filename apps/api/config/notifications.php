<?php

return [
    'enabled' => (bool) env('SLACK_NOTIFICATIONS_ENABLED', false),
    'webhook_url' => env('SLACK_WEBHOOK_URL', ''),
    'storage_thresholds_gib' => [50, 65, 75],
    'minimum_free_gib' => 15,
];
