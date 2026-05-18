<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Notifications\Core\NotificationFeedNormalizer;
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
        $normalized = $this->normalizer()->normalize($this->resource);

        return [
            'id' => $normalized['id'],
            'module' => $normalized['module'],
            'icon' => $normalized['icon'],
            'type' => $normalized['type'],
            'canonicalType' => $normalized['canonicalType'],
            'canonical_type' => $normalized['canonical_type'],
            'category' => $normalized['category'],
            'priority' => $normalized['priority'],
            'title' => $normalized['title'],
            'body' => $normalized['body'],
            'message' => $normalized['message'],
            'data' => $normalized['data'],
            'readAt' => $normalized['readAt'],
            'read_at' => $normalized['read_at'],
            'createdAt' => $normalized['createdAt'],
            'created_at' => $normalized['created_at'],
        ];
    }

    private function normalizer(): NotificationFeedNormalizer
    {
        return app(NotificationFeedNormalizer::class);
    }
}
