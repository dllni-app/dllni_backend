<?php

declare(strict_types=1);

namespace App\Notifications\Core;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;

final class NotificationPayloadBuilder
{
    public function __construct(
        private readonly NotificationTypeRegistry $registry,
        private readonly NotificationTemplateResolver $templateResolver,
    ) {}

    /**
     * @param  array<string, scalar|null>  $templateContext
     * @param  array<string, mixed>  $extraData
     * @return array<string, mixed>
     */
    public function makeDatabasePayload(
        string $canonicalType,
        array $templateContext = [],
        array $extraData = [],
        ?string $locale = null,
    ): array {
        $definition = $this->registry->definition($canonicalType);
        $copy = $this->templateResolver->resolve($canonicalType, $templateContext, $locale);

        $module = is_string($definition['module'] ?? null) ? $definition['module'] : null;
        $iconUrl = $this->resolveIconUrl($module);
        $legacyType = (string) ($definition['legacy_type'] ?? $canonicalType);

        $normalizedExtraData = $this->normalizeExtraData($extraData);

        return [
            'type' => $legacyType,
            'canonical_type' => $canonicalType,
            'canonicalType' => $canonicalType,
            'module' => $module,
            'category' => (string) ($definition['category'] ?? 'system'),
            'priority' => (string) ($definition['priority'] ?? 'normal'),
            'icon' => $iconUrl,
            'title' => $copy['title'],
            'body' => $copy['body'],
            'message' => $copy['body'],
            'data' => $normalizedExtraData,
            ...$normalizedExtraData,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $templateContext
     * @param  array<string, mixed>  $extraData
     */
    public function makeFcmMessage(
        string $canonicalType,
        array $templateContext = [],
        array $extraData = [],
        ?string $locale = null,
    ): FcmMessage {
        $databasePayload = $this->makeDatabasePayload(
            canonicalType: $canonicalType,
            templateContext: $templateContext,
            extraData: $extraData,
            locale: $locale,
        );

        $priority = mb_strtolower((string) ($databasePayload['priority'] ?? 'normal')) === 'high'
            ? MessagePriority::HIGH
            : MessagePriority::NORMAL;

        return FcmMessage::create(
            (string) ($databasePayload['title'] ?? ''),
            (string) ($databasePayload['body'] ?? ''),
        )->priority($priority)
            ->data($this->serializeDataPayload($databasePayload));
    }

    /**
     * @return array<int, string>
     */
    public function resolveChannels(string $canonicalType, object $notifiable): array
    {
        $definition = $this->registry->definition($canonicalType);
        $configured = is_array($definition['channels'] ?? null) ? $definition['channels'] : ['database'];

        $channels = [];
        foreach ($configured as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            if ($channel === 'push') {
                $fcmToken = is_callable([$notifiable, 'routeNotificationForFcm'])
                    ? $notifiable->routeNotificationForFcm(null)
                    : null;

                if (is_string($fcmToken) && $fcmToken !== '') {
                    $channels[] = 'fcm';
                }

                continue;
            }

            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function serializeDataPayload(array $payload): array
    {
        $data = [
            'type' => $payload['type'] ?? null,
            'canonical_type' => $payload['canonical_type'] ?? null,
            'canonicalType' => $payload['canonicalType'] ?? null,
            'module' => $payload['module'] ?? null,
            'category' => $payload['category'] ?? null,
            'priority' => $payload['priority'] ?? null,
        ];

        $extra = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return array_filter(
            [...$data, ...$extra],
            fn (mixed $value): bool => is_scalar($value) || $value === null
        );
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @return array<string, mixed>
     */
    private function normalizeExtraData(array $extraData): array
    {
        $normalized = $extraData;

        $deepLinkTarget = null;
        if (is_string($normalized['deep_link_target'] ?? null) && $normalized['deep_link_target'] !== '') {
            $deepLinkTarget = $normalized['deep_link_target'];
        } elseif (is_string($normalized['deepLinkTarget'] ?? null) && $normalized['deepLinkTarget'] !== '') {
            $deepLinkTarget = $normalized['deepLinkTarget'];
        }

        if (is_string($deepLinkTarget) && $deepLinkTarget !== '') {
            $normalized['deep_link_target'] = $deepLinkTarget;
            $normalized['deepLinkTarget'] = $deepLinkTarget;
        }

        if (! isset($normalized['args']) && is_string($deepLinkTarget) && $deepLinkTarget !== '') {
            $routeArgs = ['route' => $deepLinkTarget];

            foreach (['bookingId', 'orderId', 'timeWarningId', 'disputeId', 'action', 'status'] as $key) {
                if (array_key_exists($key, $normalized)) {
                    $routeArgs[$key] = $normalized[$key];
                }
            }

            $encoded = json_encode($routeArgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $normalized['args'] = $encoded;
            }
        }

        return $normalized;
    }

    private function resolveIconUrl(?string $module): ?string
    {
        $iconPath = $this->registry->iconPathForModule($module);

        if ($iconPath === null || $iconPath === '') {
            return null;
        }

        return url($iconPath);
    }
}
