<?php

declare(strict_types=1);

namespace App\Notifications\Core;

use InvalidArgumentException;

final class NotificationTypeRegistry
{
    /** @return array<string, mixed> */
    public function definition(string $canonicalType): array
    {
        $definition = $this->types()[$canonicalType] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Notification type [{$canonicalType}] is not configured.");
        }

        return $definition;
    }

    public function canonicalFromLegacy(?string $legacyType): ?string
    {
        if ($legacyType === null || $legacyType === '') {
            return null;
        }

        foreach ($this->types() as $canonicalType => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (($definition['legacy_type'] ?? null) === $legacyType) {
                return (string) $canonicalType;
            }
        }

        return null;
    }

    public function defaultLocale(): string
    {
        return (string) config('notification_types.default_locale', 'ar');
    }

    public function fallbackLocale(): string
    {
        return (string) config('notification_types.fallback_locale', 'en');
    }

    public function iconPathForModule(?string $module): ?string
    {
        if ($module === null || $module === '') {
            return null;
        }

        $iconPath = config("notification_types.module_icons.{$module}");

        return is_string($iconPath) ? $iconPath : null;
    }

    /** @return array<string, mixed> */
    private function types(): array
    {
        return array_replace(
            $this->typesFromConfigAndFile('notification_types', 'notification_types.php'),
            $this->typesFromConfigAndFile('notification_type_extensions', 'notification_type_extensions.php'),
            $this->typesFromConfigAndFile('cleaning_repeated_notification_types', 'cleaning_repeated_notification_types.php'),
            $this->typesFromConfigAndFile('platform_coupon_notification_types', 'platform_coupon_notification_types.php'),
        );
    }

    /**
     * Merge the currently loaded Laravel config with the configuration file on disk.
     *
     * Long-running queue workers can keep an older cached config array after a deploy.
     * Loading the file as a fallback means newly added notification types are still
     * discoverable until the worker/config cache is restarted. Loaded config remains
     * authoritative for keys that already exist so runtime/test overrides still work.
     *
     * @return array<string, mixed>
     */
    private function typesFromConfigAndFile(string $configKey, string $fileName): array
    {
        $configuredTypes = config($configKey.'.types', []);
        $configuredTypes = is_array($configuredTypes) ? $configuredTypes : [];

        $fileTypes = [];
        $path = config_path($fileName);

        if (is_file($path)) {
            $fileConfig = require $path;
            if (is_array($fileConfig) && is_array($fileConfig['types'] ?? null)) {
                $fileTypes = $fileConfig['types'];
            }
        }

        return array_replace($fileTypes, $configuredTypes);
    }
}
