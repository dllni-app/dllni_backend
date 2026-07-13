<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SupportCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\CleaningBooking;

/** @mixin SupportCase */
final class SupportCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->booking;

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
            'booking' => $this->whenLoaded('booking', function () use ($booking): ?array {
                if (! $booking instanceof CleaningBooking) {
                    return null;
                }

                return [
                    'id' => $booking->id,
                    'bookingNumber' => $booking->booking_number,
                    'status' => $booking->status?->value ?? $booking->status,
                    'customer' => $booking->relationLoaded('customer') && $booking->customer ? [
                        'id' => $booking->customer->id,
                        'name' => $booking->customer->name,
                        'phone' => $booking->customer->phone,
                    ] : null,
                    'worker' => $booking->relationLoaded('worker') && $booking->worker ? [
                        'id' => $booking->worker->id,
                        'name' => trim((string) $booking->worker->first_name.' '.(string) $booking->worker->last_name),
                        'phone' => $booking->worker->relationLoaded('user') ? $booking->worker->user?->phone : null,
                    ] : null,
                ];
            }),
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
}
