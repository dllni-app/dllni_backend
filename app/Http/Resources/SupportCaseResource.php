<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SupportCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

/** @mixin SupportCase */
final class SupportCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->relationLoaded('booking') ? $this->booking : null;

        return [
            'id' => $this->id,
            'caseNumber' => $this->case_number,
            'kind' => $this->kind?->value ?? $this->kind,
            'priority' => $this->priority?->value ?? $this->priority,
            'bookingId' => $this->booking_id,
            'bookingType' => $this->booking_type,
            'reporterId' => $this->reporter_id,
            'reporterRole' => $this->reporter_role?->value ?? $this->reporter_role,
            'category' => $this->category,
            'emergencyType' => ($this->kind?->value ?? $this->kind) === 'emergency' ? $this->category : null,
            'description' => $this->description,
            'message' => $this->description,
            'source' => $this->reporter_role?->value ?? $this->reporter_role,
            'status' => $this->status?->value ?? $this->status,
            'resolution' => $this->resolution?->value ?? $this->resolution,
            'resolutionNote' => $this->resolution_note,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'workerEarningsFrozen' => (bool) $this->worker_earnings_frozen,
            'reporter' => $this->whenLoaded('reporter', fn (): ?array => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'phone' => $this->reporter->phone,
            ] : null),
            'booking' => $this->whenLoaded('booking', fn (): ?array => self::bookingPayload($booking)),
            'attachments' => $this->getMedia('attachments')->map(fn ($media): array => [
                'id' => $media->id,
                'name' => $media->file_name,
                'url' => $media->getUrl(),
                'mimeType' => $media->mime_type,
            ])->values(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message): array => [
                'id' => $message->id,
                'senderId' => $message->sender_id,
                'senderRole' => $message->sender_role?->value ?? $message->sender_role,
                'senderName' => $message->sender?->name,
                'body' => $message->body,
                'attachments' => $message->getMedia('attachments')->map(fn ($media): array => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'url' => $media->getUrl(),
                ])->values(),
                'createdAt' => $message->created_at?->toISOString(),
            ])->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'id' => $event->id,
                'eventType' => $event->event_type,
                'fromStatus' => $event->from_status,
                'toStatus' => $event->to_status,
                'actorName' => $event->actor?->name,
                'metadata' => $event->metadata,
                'createdAt' => $event->created_at?->toISOString(),
            ])->values()),
            'acknowledgedAt' => $this->acknowledged_at?->toISOString(),
            'resolvedAt' => $this->resolved_at?->toISOString(),
            'triggeredAt' => $this->created_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    private static function bookingPayload(mixed $booking): ?array
    {
        if ($booking instanceof CleaningBooking) {
            return [
                'type' => 'cleaning_booking',
                'id' => $booking->id,
                'bookingNumber' => $booking->booking_number,
                'orderNumber' => $booking->booking_number,
                'status' => $booking->status?->value ?? $booking->status,
                'customer' => $booking->relationLoaded('customer') && $booking->customer ? [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'phone' => $booking->customer->phone,
                ] : null,
                'worker' => $booking->relationLoaded('worker') && $booking->worker ? [
                    'id' => $booking->worker->id,
                    'name' => $booking->worker->user?->name ?: $booking->worker->first_name,
                    'phone' => $booking->worker->relationLoaded('user') ? $booking->worker->user?->phone : null,
                ] : null,
            ];
        }

        if ($booking instanceof Order) {
            return [
                'type' => 'restaurant_order',
                'id' => $booking->id,
                'orderNumber' => $booking->order_number,
                'status' => $booking->status?->value ?? $booking->status,
                'customer' => $booking->relationLoaded('customer') && $booking->customer ? [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'phone' => $booking->customer->phone,
                ] : null,
                'merchant' => $booking->relationLoaded('restaurant') && $booking->restaurant ? [
                    'id' => $booking->restaurant->id,
                    'name' => $booking->restaurant->name,
                    'phone' => $booking->restaurant->phone,
                ] : null,
            ];
        }

        if ($booking instanceof SmOrder) {
            return [
                'type' => 'supermarket_order',
                'id' => $booking->id,
                'orderNumber' => $booking->order_number,
                'status' => $booking->status?->value ?? $booking->status,
                'customer' => $booking->relationLoaded('customer') && $booking->customer ? [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'phone' => $booking->customer->phone,
                ] : null,
                'merchant' => $booking->relationLoaded('store') && $booking->store ? [
                    'id' => $booking->store->id,
                    'name' => $booking->store->name,
                    'phone' => $booking->store->phone,
                ] : null,
            ];
        }

        if ($booking instanceof DeliveryOrder) {
            return [
                'type' => 'delivery_order',
                'id' => $booking->id,
                'orderNumber' => $booking->order_number,
                'status' => $booking->status?->value ?? $booking->status,
                'customer' => $booking->relationLoaded('createdBy') && $booking->createdBy ? [
                    'id' => $booking->createdBy->id,
                    'name' => $booking->createdBy->name,
                    'phone' => $booking->createdBy->phone,
                ] : [
                    'id' => $booking->created_by_user_id,
                    'name' => $booking->customer_name,
                    'phone' => $booking->customer_phone,
                ],
                'driver' => $booking->relationLoaded('driver') && $booking->driver?->exists ? [
                    'id' => $booking->driver->id,
                    'name' => $booking->driver->user?->name ?: $booking->driver->first_name,
                    'phone' => $booking->driver->user?->phone ?: $booking->driver->phone,
                ] : null,
                'company' => $booking->relationLoaded('company') && $booking->company ? [
                    'id' => $booking->company->id,
                    'name' => $booking->company->name,
                    'phone' => $booking->company->phone,
                ] : null,
            ];
        }

        return null;
    }
}
