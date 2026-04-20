<?php

declare(strict_types=1);

return [
    'canonical_host' => env('DEEP_LINK_CANONICAL_HOST', 'app.dllni.com'),
    'canonical_scheme' => env('DEEP_LINK_CANONICAL_SCHEME', 'https'),

    // Web fallback when the app is not installed or universal links are not available.
    'web_landing_url' => env('DEEP_LINK_WEB_LANDING_URL', 'https://app.dllni.com/open'),

    // Optional store fallback used by client-side landing if needed.
    'store_landing_url' => env('DEEP_LINK_STORE_LANDING_URL', 'https://app.dllni.com/get-app'),

    // Safe fallback for invalid/unknown links.
    'invalid_fallback_url' => env('DEEP_LINK_INVALID_FALLBACK_URL', 'https://app.dllni.com/not-found'),

    'resolver_cache_ttl_seconds' => (int) env('DEEP_LINK_RESOLVER_CACHE_TTL_SECONDS', 300),
];
