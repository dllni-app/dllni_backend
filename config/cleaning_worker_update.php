<?php

declare(strict_types=1);

return [
    // Private worker APK bridge is DISABLED until an actual release APK has
    // been signed with the verified production certificate and hosted safely.
    'enabled' => (bool) env('CLEANING_WORKER_APK_UPDATE_ENABLED', false),
    'package_id' => 'com.dllni.clOwner',
    'min_supported_version_code' => (int) env('CLEANING_WORKER_APK_MIN_VERSION_CODE', 0),
    'latest_version_code' => (int) env('CLEANING_WORKER_APK_LATEST_VERSION_CODE', 0),
    'apk_sha256' => env('CLEANING_WORKER_APK_SHA256'),
    'signing_certificate_sha256' => env('CLEANING_WORKER_APK_CERT_SHA256'),
    'download_url' => env('CLEANING_WORKER_APK_DOWNLOAD_URL'),
    // Exact host allowlist prevents a configuration typo from pointing the
    // installed APK updater to another domain or an unsafe address.
    'allowed_download_host' => env('CLEANING_WORKER_APK_DOWNLOAD_HOST'),
];
