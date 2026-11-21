<?php

declare(strict_types=1);

return [
    // Keep V2 the only customer-facing contract until the approved V3 coverage,
    // pricing, refund and cleared-funding acceptance gates all pass.
    'read_enabled' => (bool) env('CLEANING_V3_CATALOG_READ_ENABLED', false),
    // Requires the exact approved immutable version digest; a toggle alone
    // cannot make a manually marked-as-published draft publicly visible.
    'approved_publication_digest' => env('CLEANING_V3_APPROVED_PUBLICATION_DIGEST'),
    'renderer_schema_version' => 3,
    'min_renderer_revision' => 1,
];
