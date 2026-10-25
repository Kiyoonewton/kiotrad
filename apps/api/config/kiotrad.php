<?php

return [
    'local_data_mirror' => (bool) env('LOCAL_DATA_MIRROR', false),
    'dashboard_async' => (bool) env('DASHBOARD_ASYNC', false),
    'simulation_cache_enabled' => (bool) env('SIMULATION_CACHE_ENABLED', false),
    'simulation_capital_minor' => max(100, min(10000000, (int) env('SIMULATION_CAPITAL_NAIRA', 7000))) * 100,
    'display_timezone' => 'Africa/Lagos',
    'currency' => 'NGN',
    'competitions' => [
        ['id' => 'virtual-football', 'name' => 'English'],
        ['id' => 'virtual-bundesliga', 'name' => 'Bundesliga'],
        ['id' => 'virtual-euro', 'name' => 'Euro'],
    ],
];
