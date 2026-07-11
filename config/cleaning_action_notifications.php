<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('CLEANING_ACTION_NOTIFICATIONS_ENABLED', true),
    'timezone' => env('CLEANING_ACTION_NOTIFICATIONS_TIMEZONE', config('app.timezone')),
    'lookahead_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_LOOKAHEAD_MINUTES', 65),
    'lookback_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_LOOKBACK_MINUTES', 180),
    'chunk_size' => (int) env('CLEANING_ACTION_NOTIFICATIONS_CHUNK_SIZE', 100),
    'retry_failed_after_minutes' => (int) env('CLEANING_ACTION_NOTIFICATIONS_RETRY_FAILED_AFTER_MINUTES', 5),

    /*
     * Repeated reminders are emitted only while the required action is still
     * pending. Each occurrence is deduplicated independently, and the rule
     * engine stops producing occurrences as soon as the booking state changes.
     */
    'repeat_policies' => [
        'worker_start_travel_warning' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_TRAVEL_WARNING_INTERVAL_MINUTES', 5),
            'max_occurrences' => (int) env('CLEANING_REPEAT_TRAVEL_WARNING_MAX_OCCURRENCES', 2),
        ],
        'worker_arrival_critical_warning' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_ARRIVAL_WARNING_INTERVAL_MINUTES', 5),
            'max_occurrences' => (int) env('CLEANING_REPEAT_ARRIVAL_WARNING_MAX_OCCURRENCES', 12),
        ],
        'worker_security_code_issue_reminder' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_SECURITY_CODE_ISSUE_INTERVAL_MINUTES', 2),
            'max_occurrences' => (int) env('CLEANING_REPEAT_SECURITY_CODE_ISSUE_MAX_OCCURRENCES', 4),
        ],
        'customer_verification_reminder' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_SECURITY_CODE_INTERVAL_MINUTES', 2),
            'max_occurrences' => (int) env('CLEANING_REPEAT_SECURITY_CODE_MAX_OCCURRENCES', 3),
        ],
        'worker_start_confirmation_warning' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_START_CONFIRMATION_INTERVAL_MINUTES', 5),
            'max_occurrences' => (int) env('CLEANING_REPEAT_START_CONFIRMATION_MAX_OCCURRENCES', 5),
        ],
        'customer_completion_action_reminder' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_COMPLETION_ACTION_INTERVAL_MINUTES', 5),
            'max_occurrences' => (int) env('CLEANING_REPEAT_COMPLETION_ACTION_MAX_OCCURRENCES', 6),
        ],
        'worker_extension_response_reminder' => [
            'interval_minutes' => (int) env('CLEANING_REPEAT_EXTENSION_RESPONSE_INTERVAL_MINUTES', 5),
            'max_occurrences' => (int) env('CLEANING_REPEAT_EXTENSION_RESPONSE_MAX_OCCURRENCES', 6),
        ],
    ],
];
