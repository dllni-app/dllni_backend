<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Support\DeliveryPresentation;

final class DeliveryOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DeliveryOrder $order */
        $order = $this->resource;
        $tracking = DeliveryPresentation::orderTracking($order);

        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'companyId' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'phone' => $this->company->phone ?? null,
                'email' => $this->company->email ?? null,
            ]),
            'driverId' => $this->driver_id,
            'driver' => DeliveryDriverResource::make($this->whenLoaded('driver')),
            'status' => $this->status,
            'statusLabelAr' => DeliveryPresentation::statusLabelAr((string) $this->status),
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'customerNotes' => $this->customer_notes,
            'pickupAddress' => $this->pickup_address,
            'pickupLatitude' => (float) $this->pickup_latitude,
            'pickupLongitude' => (float) $this->pickup_longitude,
            'dropoffAddress' => $this->dropoff_address,
            'dropoffLatitude' => (float) $this->dropoff_latitude,
            'dropoffLongitude' => (float) $this->dropoff_longitude,
            'distanceKm' => (float) $this->distance_km,
            'deliveryFee' => (float) $this->delivery_fee,
            'currency' => $this->currency,
            'acceptedAt' => $this->accepted_at?->toIso8601String(),
            'startedAt' => $this->started_at?->toIso8601String(),
            'pickedUpAt' => $this->picked_up_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'stoppedAt' => $this->stopped_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'stopReason' => $this->stop_reason,
            'cancelReason' => $this->cancel_reason,
            'timeline' => $tracking['timeline'] ?? [],
            'tracking' => $tracking,
            'events' => DeliveryOrderEventResource::collection($this->whenLoaded('events')),
            'createdByUserId' => $this->created_by_user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
