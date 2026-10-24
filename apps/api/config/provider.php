<?php

return [
    // Initial conservative check, not a claim of exact match duration. Missing results retry.
    'result_check_delay_seconds' => max(30, min(600, (int) env('PROVIDER_RESULT_CHECK_DELAY_SECONDS', 200))),
    // Public feed routes verified against the provider's statistics application.
    'statistics_base' => 'https://vgls-vs001.akamaized.net/vfl/feeds/?/bet9javirtuals/en/Europe:Berlin/gismo/',
    'odds_base' => 'https://vgls.betradar.com/vfl/feeds/?/bet9ja/en/Europe:Berlin/gismo/',
    'competitions' => [
        'virtual-football' => ['seed' => env('PROVIDER_ENGLISH_SEED', '3148778'), 'tournament_uid' => 31867],
        'virtual-bundesliga' => ['seed' => env('PROVIDER_BUNDESLIGA_SEED', '3148850'), 'tournament_uid' => 34616],
        'virtual-euro' => ['seed' => env('PROVIDER_EURO_SEED', '3148615'), 'tournament_uid' => 14149],
    ],
];
