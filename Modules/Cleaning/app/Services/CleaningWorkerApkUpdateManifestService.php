<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

final class CleaningWorkerApkUpdateManifestService
{
    public const PACKAGE_ID = 'com.dllni.clOwner';

    /** @return array<string, int|string> */
    public function manifest(): array
    {
        $config = config('cleaning_worker_update', []);
        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            throw new DomainException('WORKER_APK_UPDATE_NOT_CONFIGURED');
        }

        // The package identity is pinned in backend source. A different
        // package would be a new installation, not an in-place APK upgrade.
        if (($config['package_id'] ?? null) !== self::PACKAGE_ID) {
            throw new DomainException('WORKER_APK_UPDATE_PACKAGE_MISMATCH');
        }

        $minimum = $config['min_supported_version_code'] ?? null;
        $latest = $config['latest_version_code'] ?? null;
        $apkDigest = $config['apk_sha256'] ?? null;
        $signingDigest = $config['signing_certificate_sha256'] ?? null;
        $downloadUrl = $config['download_url'] ?? null;
        $allowedHost = $config['allowed_download_host'] ?? null;

        if (! is_int($minimum) || ! is_int($latest)
            || $minimum < 1 || $latest < $minimum
            || ! $this->validSha256($apkDigest) || ! $this->validSha256($signingDigest)) {
            throw new DomainException('WORKER_APK_UPDATE_METADATA_INVALID');
        }

        if (! is_string($allowedHost)
            || ! filter_var($allowedHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($allowedHost, FILTER_VALIDATE_IP)
            || mb_strtolower($allowedHost) !== $allowedHost
            || ! is_string($downloadUrl)) {
            throw new DomainException('WORKER_APK_UPDATE_URL_INVALID');
        }

        $parts = parse_url($downloadUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $allowedHost
            || ! isset($parts['path']) || $parts['path'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new DomainException('WORKER_APK_UPDATE_URL_INVALID');
        }

        return [
            'schemaVersion' => 1,
            'packageId' => self::PACKAGE_ID,
            'minSupportedVersionCode' => $minimum,
            'latestVersionCode' => $latest,
            'apkSha256' => mb_strtolower($apkDigest),
            'signingCertificateSha256' => mb_strtolower($signingDigest),
            'downloadUrl' => $downloadUrl,
        ];
    }

    private function validSha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{64}$/Di', $value) === 1;
    }
}
