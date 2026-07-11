<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('CLEANING_ACTION_NOTIFICATIONS_ENABLED', true),
    'timezone' => env('CLEANING_ACTION_NOTIFICATIONS_TIMEZONE', config('app.timezone')),
    'lookahead_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_LOOKAHEAD_MINUTES', 65),
    'lookback_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_LOOKBACK_MINUTES', 180),
    'chunk_size' => (int) env('CLEANING_ACTION_NOTIFICATIONS_CHUNK_SIZE', 100),
    'retry_failed_after_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_RETRY_FAILED_AFTER_MINUTES', 5),
];
