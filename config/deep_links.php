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

    // Android App Links settings used at /.well-known/assetlinks.json.
    'android_app_package_name' => env('DEEP_LINK_ANDROID_PACKAGE_NAME', ''),
    'android_sha256_cert_fingerprints' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env('DEEP_LINK_ANDROID_SHA256_CERT_FINGERPRINTS', '')),
    ))),

    // iOS Universal Links settings used at /.well-known/apple-app-site-association.
    'ios_app_ids' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env('DEEP_LINK_IOS_APP_IDS', '')),
    ))),
    'ios_paths' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env('DEEP_LINK_IOS_PATHS', '/product/*,/restaurant/*,/store/*,/vote/*,/group-order/*,/s/*,/v1/user/products/*,/v1/user/supermarket/products/*,/v1/user/restaurants/*,/api/v1/user/restaurants/votes/*,/api/v1/user/restaurants/group-orders/*,/api/v1/user/supermarket/stores/*')),
    ))),

    'resolver_cache_ttl_seconds' => (int) env('DEEP_LINK_RESOLVER_CACHE_TTL_SECONDS', 300),
];
