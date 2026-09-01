<?php

declare(strict_types=1);

return [
    // Dev branch defaults. Production can continue overriding these through
    // DEEP_LINK_* environment variables without sharing dev links by mistake.
    'canonical_host' => env('DEEP_LINK_CANONICAL_HOST', 'dllni.mustafafares.com'),
    'canonical_scheme' => env('DEEP_LINK_CANONICAL_SCHEME', 'https'),

    'web_landing_url' => env('DEEP_LINK_WEB_LANDING_URL', 'https://dllni.mustafafares.com/open'),
    'store_landing_url' => env('DEEP_LINK_STORE_LANDING_URL', 'https://dllni.mustafafares.com/get-app'),
    'invalid_fallback_url' => env('DEEP_LINK_INVALID_FALLBACK_URL', 'https://dllni.mustafafares.com/not-found'),

    // Keep these defaults in sync with dllni-user-app signing configuration so
    // a dev server works even when DEEP_LINK_* variables are not injected.
    'android_app_package_name' => env('DEEP_LINK_ANDROID_PACKAGE_NAME', 'com.alnadha.app'),
    'android_sha256_cert_fingerprints' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env(
            'DEEP_LINK_ANDROID_SHA256_CERT_FINGERPRINTS',
            'CF:73:C0:B3:7B:16:17:D9:02:50:84:3F:3A:6A:C9:AD:AF:CB:E9:56:70:6A:97:9C:7E:AC:EC:4D:46:9D:E1:B5'
        )),
    ))),

    'ios_app_ids' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env('DEEP_LINK_IOS_APP_IDS', 'C4S72M3DX2.com.alnadha.app')),
    ))),
    'ios_paths' => array_values(array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(',', (string) env(
            'DEEP_LINK_IOS_PATHS',
            '/product/*,/restaurant/*,/store/*,/vote/*,/group-order/*,/s/*,/api/v1/user/*,/api/v1/deep-links/*,/open'
        )),
    ))),

    'resolver_cache_ttl_seconds' => (int) env('DEEP_LINK_RESOLVER_CACHE_TTL_SECONDS', 300),
];
