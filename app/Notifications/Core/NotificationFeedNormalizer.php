<?php

declare(strict_types=1);

namespace App\Notifications\Core;

use InvalidArgumentException;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationFeedNormalizer
{
    public function __construct(
        private readonly NotificationTypeRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($notification->data) ? $notification->data : [];

        $normalized = $this->normalizeData($data, $notification->type);

        return [
            'id' => $notification->id,
            ...$normalized,
            'readAt' => $notification->read_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeData(array $data, string $notificationClass): array
    {
        $legacyType = is_string($data['type'] ?? null) ? $data['type'] : null;
        $canonicalType = is_string($data['canonical_type'] ?? null)
            ? $data['canonical_type']
            : $this->registry->canonicalFromLegacy($legacyType);

        $definition = $this->resolveDefinition($canonicalType);

        $module = $this->resolveModule($data, $definition, $notificationClass);
        $icon = $this->resolveIcon($data, $module);
        $category = $this->resolveCategory($data, $definition, $legacyType);
        $priority = (string) ($data['priority'] ?? ($definition['priority'] ?? 'normal'));
        $title = (string) ($data['title'] ?? '');
        $body = (string) ($data['body'] ?? '');
        $message = (string) ($data['message'] ?? $body);
        $normalizedData = $this->extractData($data);

        return [
            'type' => $legacyType,
            'canonicalType' => $canonicalType,
            'canonical_type' => $canonicalType,
            'module' => $module,
            'icon' => $icon,
            'category' => $category,
            'priority' => $priority,
            'title' => $title,
            'body' => $body,
            'message' => $message,
            'data' => $normalizedData,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDefinition(?string $canonicalType): ?array
    {
        if ($canonicalType === null || $canonicalType === '') {
            return null;
        }

        try {
            return $this->registry->definition($canonicalType);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $definition
     */
    private function resolveModule(array $data, ?array $definition, string $notificationClass): ?string
    {
        $module = $data['module'] ?? null;
        if (is_string($module) && $module !== '') {
            return $module;
        }

        $definedModule = $definition['module'] ?? null;
        if (is_string($definedModule) && $definedModule !== '') {
            return $definedModule;
        }

        if (str_contains($notificationClass, '\\Cleaning\\')) {
            return 'cleaning';
        }

        if (str_contains($notificationClass, '\\Supermarket\\')) {
            return 'supermarket';
        }

        if (str_contains($notificationClass, '\\Resturants\\') || str_contains($notificationClass, '\\Restaurant\\')) {
            return 'restaurant';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIcon(array $data, ?string $module): ?string
    {
        $icon = $data['icon'] ?? null;
        if (is_string($icon) && $icon !== '') {
            return $icon;
        }

        $iconPath = $this->registry->iconPathForModule($module);

        return is_string($iconPath) ? url($iconPath) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $definition
     */
    private function resolveCategory(array $data, ?array $definition, ?string $legacyType): string
    {
        if (is_string($data['category'] ?? null) && $data['category'] !== '') {
            return (string) $data['category'];
        }

        if (is_array($definition) && is_string($definition['category'] ?? null) && $definition['category'] !== '') {
            return (string) $definition['category'];
        }

        $type = mb_strtolower((string) ($legacyType ?? ''));

        if (str_contains($type, 'offer') || str_contains($type, 'coupon') || str_contains($type, 'promo')) {
            return 'offers';
        }

        if (str_contains($type, 'order')) {
            return 'orders';
        }

        return 'system';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractData(array $data): array
    {
        if (is_array($data['data'] ?? null)) {
            return $data['data'];
        }

        $excludedKeys = [
            'type',
            'canonical_type',
            'module',
            'icon',
            'category',
            'priority',
            'title',
            'body',
            'message',
        ];

        $normalized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || in_array($key, $excludedKeys, true)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $this->normalizeRoutingData($normalized);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeRoutingData(array $data): array
    {
        $deepLinkTarget = null;
        if (is_string($data['deep_link_target'] ?? null) && $data['deep_link_target'] !== '') {
            $deepLinkTarget = $data['deep_link_target'];
        } elseif (is_string($data['deepLinkTarget'] ?? null) && $data['deepLinkTarget'] !== '') {
            $deepLinkTarget = $data['deepLinkTarget'];
        }

        if (is_string($deepLinkTarget) && $deepLinkTarget !== '') {
            $data['deep_link_target'] = $deepLinkTarget;
            $data['deepLinkTarget'] = $deepLinkTarget;
        }

        if (! isset($data['args']) && is_string($deepLinkTarget) && $deepLinkTarget !== '') {
            $routeArgs = ['route' => $deepLinkTarget];

            foreach (['bookingId', 'orderId', 'timeWarningId', 'disputeId', 'action', 'status'] as $key) {
                if (array_key_exists($key, $data)) {
                    $routeArgs[$key] = $data[$key];
                }
            }

            $encoded = json_encode($routeArgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $data['args'] = $encoded;
            }
        }

        return $data;
    }
}
