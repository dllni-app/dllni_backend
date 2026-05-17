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
        $types = config('notification_types.types', []);
        $definition = is_array($types) ? ($types[$canonicalType] ?? null) : null;

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

        $types = config('notification_types.types', []);

        if (! is_array($types)) {
            return null;
        }

        foreach ($types as $canonicalType => $definition) {
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
}
