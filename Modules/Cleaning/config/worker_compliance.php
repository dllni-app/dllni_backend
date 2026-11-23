<?php

declare(strict_types=1);

// The dev pilot is available only on explicitly non-production Laravel
// environments. A configured HTTPS notice, individual enrollment and device
// consent are still required before a single sample can be collected.
// Environment variables always override these safe staging defaults.
$devPilotDefaults = in_array((string) env('APP_ENV', 'production'), ['local', 'development', 'staging'], true);

return [
    'enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_ENABLED', $devPilotDefaults),
    'matching_enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_MATCHING_ENABLED', $devPilotDefaults),
    // Staff notification rollout is independent of collection and matching.
    'notifications_enabled' => (bool) env('CLEANING_WORKER_COMPLIANCE_NOTIFICATIONS_ENABLED', $devPilotDefaults),
    'enabled_platforms' => array_values(array_filter(array_map(
        static fn (string $platform): string => mb_strtolower(mb_trim($platform)),
        explode(',', (string) env('CLEANING_WORKER_COMPLIANCE_ENABLED_PLATFORMS', $devPilotDefaults ? 'android' : '')),
    ))),
    'notice_version' => (string) env('CLEANING_WORKER_COMPLIANCE_NOTICE_VERSION', '2026-11-23-v2'),
    'minimum_supported_build' => (string) env('CLEANING_WORKER_COMPLIANCE_MINIMUM_BUILD', '0'),
    'privacy_policy_url' => (string) env(
        'CLEANING_WORKER_COMPLIANCE_PRIVACY_URL',
        rtrim((string) env('APP_URL', ''), '/').'/legal/cleaning-worker-monitoring',
    ),
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
