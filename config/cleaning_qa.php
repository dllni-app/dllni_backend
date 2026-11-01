<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('CLEANING_QA_FULL_TEST_MODE', false),
    'time_travel' => (bool) env('CLEANING_QA_TIME_TRAVEL_ENABLED', false),
    'allow_early_actions' => (bool) env('CLEANING_QA_ALLOW_EARLY_ACTIONS', false),
    'database' => 'alnadha_worker_test',
    // Explicitly seeded independent QA bookings; do not allow historical
    // removed visit identifiers to grant any temporal scheduling override.
    // Real verification and financial timestamps are never overridden.
    'bookings' => [
        'CLN-QA-INDEPENDENT-ORDER-1',
        'CLN-QA-INDEPENDENT-ORDER-2',
        'CLN-QA-INDEPENDENT-ORDER-3',
    ],
    'actors' => [
        'admin@admin.com', 'user@dllni.sy', 'cleaning.worker@dllni.sy',
        'cleaning.worker2@dllni.sy', 'cleaning.worker3@dllni.sy',
        'supervisor@dllni.sy', 'phase2.supervisor@dllni.sy',
        'phase27.solo.worker@dllni.sy',
    ],
];
