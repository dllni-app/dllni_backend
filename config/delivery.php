<?php

declare(strict_types=1);

return [
    'pricing' => [
        'base_fee' => (float) env('DELIVERY_BASE_FEE', 5000),
        'per_km_rate' => (float) env('DELIVERY_PER_KM_RATE', 1000),
        'minimum_fee' => (float) env('DELIVERY_MINIMUM_FEE', 5000),
        'default_currency' => env('DELIVERY_DEFAULT_CURRENCY', 'SYP'),
    ],
    'dispatch' => [
        'stale_location_minutes' => (int) env('DELIVERY_STALE_LOCATION_MINUTES', 5),
        'offer_timeout_seconds' => (int) env('DELIVERY_OFFER_TIMEOUT_SECONDS', 60),
        'initial_search_radius_km' => (float) env('DELIVERY_INITIAL_SEARCH_RADIUS_KM', 5),
        'stop_when_no_driver' => filter_var(env('DELIVERY_STOP_WHEN_NO_DRIVER', false), FILTER_VALIDATE_BOOL),
        'no_candidate_retry_seconds' => (int) env('DELIVERY_NO_CANDIDATE_RETRY_SECONDS', 60),
        'max_no_candidate_retries' => (int) env('DELIVERY_MAX_NO_CANDIDATE_RETRIES', 10),
    ],
    'trust' => [
        'default_score' => (int) env('DELIVERY_DRIVER_DEFAULT_TRUST_SCORE', 100),
        'max_score' => (int) env('DELIVERY_DRIVER_MAX_TRUST_SCORE', 100),
        'daily_recovery_points' => (int) env('DELIVERY_DRIVER_DAILY_RECOVERY_POINTS', 1),
        'dispute_penalty' => (int) env('DELIVERY_DRIVER_DISPUTE_PENALTY', 10),
    ],
    'financial' => [
        'dispute_penalty_amount' => (float) env('DELIVERY_DISPUTE_PENALTY_AMOUNT', 0),
    ],
];
