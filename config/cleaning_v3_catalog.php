<?php

declare(strict_types=1);

return [
    // Keep V2 the only customer-facing contract until the approved V3 coverage,
    // pricing, refund and cleared-funding acceptance gates all pass.
    'read_enabled' => (bool) env('CLEANING_V3_CATALOG_READ_ENABLED', false),
    // Requires the exact approved immutable version digest; a toggle alone
    // cannot make a manually marked-as-published draft publicly visible.
    'approved_publication_digest' => env('CLEANING_V3_APPROVED_PUBLICATION_DIGEST'),
    'admin_publication_enabled' => (bool) env('CLEANING_V3_ADMIN_PUBLICATION_ENABLED', false),
    'cash_quote_enabled' => (bool) env('CLEANING_V3_CASH_QUOTE_ENABLED', false),
    // Independent cash custodian's Ed25519 public key; no private key here.
    'treasury_provider' => env('CLEANING_V3_TREASURY_PROVIDER'),
    'treasury_public_key' => env('CLEANING_V3_TREASURY_PUBLIC_KEY'),
    'cash_settlement_enabled' => (bool) env('CLEANING_V3_CASH_SETTLEMENT_ENABLED', false),
    'renderer_schema_version' => 3,
    'min_renderer_revision' => 1,
    // Independent rollback switches for NEW V3 commercial work only.
    // Existing accepted V3 visits never consult these flags for execution.
    'new_quote_archive_enabled' => (bool) env('CLEANING_V3_NEW_QUOTE_ARCHIVE_ENABLED', false),
    'new_booking_admission_enabled' => (bool) env('CLEANING_V3_NEW_BOOKING_ADMISSION_ENABLED', false),
    // Compatibility rollout for NEW independently booked recurring days.
    // Existing accepted bookings remain readable and executable when disabled.
    'independent_daily_bookings_enabled' => (bool) env('CLEANING_V3_INDEPENDENT_DAILY_BOOKINGS_ENABLED', false),
    // New operational approvals only. Never bypass verified funding or release evidence.
    'operational_approval_enabled' => (bool) env('CLEANING_V3_OPERATIONAL_APPROVAL_ENABLED', false),
    'qa_enabled' => (bool) env('CLEANING_V3_QA_ENABLED', false),
    'qa_signing_key' => env('CLEANING_V3_QA_SIGNING_KEY'),
];
