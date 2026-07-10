<?php

declare(strict_types=1);

namespace App\Notifications\Core;

use InvalidArgumentException;

final class NotificationTypeRegistry
{
    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    private function types(): array
    {
        $configuredTypes = config('notification_types.types', []);
        $extensionTypes = config('notification_type_extensions.types', []);

        return array_replace(
            is_array($configuredTypes) ? $configuredTypes : [],
            is_array($extensionTypes) ? $extensionTypes : [],
        );
    }
}
