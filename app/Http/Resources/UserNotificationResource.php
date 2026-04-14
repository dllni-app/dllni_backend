<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
final class UserNotificationResource extends JsonResource
{
    private const CLEANING_TYPES = [
        'new_order',
        'extension_request',
        'dispute_opened',
    ];

    private const SUPERMARKET_TYPES = [
        'smart_list_scheduled_order_sent',
        'smart_list_scheduled_order_failed',
    ];

    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'module' => $this->resolveModule($data),
            'icon' => $this->resolveIconUrl($data),
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'data' => array_filter([
                'bookingId' => $data['bookingId'] ?? null,
                'timeWarningId' => $data['timeWarningId'] ?? null,
                'disputeId' => $data['disputeId'] ?? null,
            ], fn ($v) => $v !== null),
            'readAt' => $this->read_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveModule(array $data): ?string
    {
        if (isset($data['module']) && in_array($data['module'], ['restaurant', 'supermarket', 'cleaning'], true)) {
            return $data['module'];
        }

        if (str_contains($this->type, '\\Cleaning\\')) {
            return 'cleaning';
        }

        if (str_contains($this->type, '\\Supermarket\\')) {
            return 'supermarket';
        }

        if (str_contains($this->type, '\\Resturants\\') || str_contains($this->type, '\\Restaurant\\')) {
            return 'restaurant';
        }

        $payloadType = $data['type'] ?? null;

        if (is_string($payloadType) && in_array($payloadType, self::CLEANING_TYPES, true)) {
            return 'cleaning';
        }

        if (is_string($payloadType) && in_array($payloadType, self::SUPERMARKET_TYPES, true)) {
            return 'supermarket';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIconUrl(array $data): ?string
    {
        if (isset($data['icon']) && is_string($data['icon']) && $data['icon'] !== '') {
            return $data['icon'];
        }

        return match ($this->resolveModule($data)) {
            'cleaning' => url('/images/notifications/cleaning.svg'),
            'supermarket' => url('/images/notifications/supermarket.svg'),
            'restaurant' => url('/images/notifications/restaurant.svg'),
            default => null,
        };
    }
}
