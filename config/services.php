<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'dallelni_search' => [
        'auth_token' => env('DALLELNI_SEARCH_AUTH_TOKEN', env('AUTH_TOKEN', '')),
        'products_base_url' => env('DALLELNI_PRODUCTS_SEARCH_BASE_URL', 'https://dallelni.karriya.ai/products'),
        'restaurant_products_base_url' => env('DALLELNI_RESTAURANT_PRODUCTS_SEARCH_BASE_URL', env('DALLELNI_PRODUCTS_SEARCH_BASE_URL', 'https://dallelni.karriya.ai/products')),
        'stores_base_url' => env('DALLELNI_SM_STORES_SEARCH_BASE_URL', 'https://dallelni.karriya.ai/sm-stores'),
        'timeout' => env('DALLELNI_SEARCH_TIMEOUT', 10),
    ],

    'openfoodfacts' => [
        'products_jsonl_url' => env('OPENFOODFACTS_PRODUCTS_JSONL_URL', 'https://static.openfoodfacts.org/data/openfoodfacts-products.jsonl.gz'),
        'user_agent' => env('OPENFOODFACTS_USER_AGENT', 'DllniBackend/1.0 (contact: backend@dllni.local)'),
        'timeout' => (int) env('OPENFOODFACTS_TIMEOUT', 120),
        'retry_times' => (int) env('OPENFOODFACTS_RETRY_TIMES', 3),
        'retry_sleep' => (int) env('OPENFOODFACTS_RETRY_SLEEP', 500),
        'image_max_bytes' => (int) env('OPENFOODFACTS_IMAGE_MAX_BYTES', 5 * 1024 * 1024),
        'image_sleep_ms' => (int) env('OPENFOODFACTS_IMAGE_SLEEP_MS', 100),
    ],

];
