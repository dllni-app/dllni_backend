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
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
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
}
