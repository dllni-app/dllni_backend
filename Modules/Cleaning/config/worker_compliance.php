<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_ENABLED', false),
    'matching_enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_MATCHING_ENABLED', false),
    // Staff notification rollout is independent of collection and matching.
    'notifications_enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_NOTIFICATIONS_ENABLED', false),
    'enabled_platforms' => array_values(array_filter(array_map(
        static fn (string $platform): string => mb_strtolower(mb_trim($platform)),
        explode(',', (string) env('CLEANING_WORKER_COMPLIANCE_ENABLED_PLATFORMS', '')),
    ))),
    'notice_version' => (string) env('CLEANING_WORKER_COMPLIANCE_NOTICE_VERSION', '2026-11-20-v1'),
    'minimum_supported_build' => (string) env('CLEANING_WORKER_COMPLIANCE_MINIMUM_BUILD', '0'),
    'privacy_policy_url' => (string) env('CLEANING_WORKER_COMPLIANCE_PRIVACY_URL', ''),
    'session_lease_minutes' => 60,
    'sample_retention_days' => 7,
    'maximum_batch_size' => 20,
    'maximum_sample_age_minutes' => 15,
    'maximum_future_skew_minutes' => 5,
    'maximum_accuracy_m' => 50,
    'proximity_radius_m' => 100,
    'minimum_sample_count' => 4,
    'minimum_dwell_seconds' => 600,
    'maximum_sample_gap_seconds' => 240,
    'matching_reorder_buffer_seconds' => 120,
    'maximum_dwell_path_m' => 300,
    'site_key_secret' => (string) env('CLEANING_WORKER_COMPLIANCE_SITE_KEY_SECRET', env('APP_KEY', '')),
    'site_eligibility_retention_days' => 90,
    'unserved_site_snapshot_retention_days' => 30,
    'review_case_retention_days' => 90,
    'review_audit_retention_days' => 180,
    'review_cooldown_hours' => 24,
    'rule_version' => 'pilot-v1',
];
