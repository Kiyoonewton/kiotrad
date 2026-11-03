<?php

return [
    'alert_settings_token' => env('QUALIFICATION_ALERT_SETTINGS_TOKEN', ''),
    'slack_qualification' => [
        'enabled' => (bool) env('QUALIFICATION_SLACK_ENABLED', false),
        'settings_token' => env('QUALIFICATION_SLACK_SETTINGS_TOKEN', env('QUALIFICATION_EMAIL_SETTINGS_TOKEN', '')),
    ],
    'email' => [
        'settings_token' => env('QUALIFICATION_EMAIL_SETTINGS_TOKEN', ''),
        'enabled' => (bool) env('QUALIFICATION_EMAIL_ENABLED', false),
        'to' => env('QUALIFICATION_EMAIL_TO', ''),
        'capital_minor' => (int) env('QUALIFICATION_EMAIL_CAPITAL_MINOR', 5000000),
        'lead_seconds' => (int) env('QUALIFICATION_EMAIL_LEAD_SECONDS', 10),
    ],
    'enabled' => (bool) env('SLACK_NOTIFICATIONS_ENABLED', false),
    'webhook_url' => env('SLACK_WEBHOOK_URL', ''),
    'storage_thresholds_gib' => [50, 65, 75],
    'minimum_free_gib' => 15,
];
