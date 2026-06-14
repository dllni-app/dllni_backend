<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Push Notification Queue
    |--------------------------------------------------------------------------
    |
    | Queued notifications that include FCM push should use this queue so
    | workers can prioritize them ahead of general background jobs.
    |
    | Recommended worker command:
    | php artisan queue:work --queue=push-notifications,notifications,default
    |
    */
    'push_queue' => env('NOTIFICATIONS_PUSH_QUEUE', 'push-notifications'),

    /*
    |--------------------------------------------------------------------------
    | FCM HTTP Client
    |--------------------------------------------------------------------------
    */
    'fcm' => [
        'oauth_cache_key' => env('FCM_OAUTH_CACHE_KEY', 'fcm.google_access_token'),
        'oauth_cache_ttl_margin' => (int) env('FCM_OAUTH_CACHE_TTL_MARGIN', 300),
        'http_timeout' => (int) env('FCM_HTTP_TIMEOUT', 10),
        'http_connect_timeout' => (int) env('FCM_HTTP_CONNECT_TIMEOUT', 5),
        'retry_times' => (int) env('FCM_HTTP_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('FCM_HTTP_RETRY_SLEEP_MS', 100),
        'logging_enabled' => (bool) env('FCM_PUSH_LOGGING_ENABLED', true),
    ],

];
