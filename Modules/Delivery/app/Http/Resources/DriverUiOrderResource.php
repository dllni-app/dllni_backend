<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Delivery\Enums\DeliveryOrderStatus;

final class DriverUiOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $events = $this->whenLoaded('events');
        $arrivedPickup = false;
        $arrivedDropoff = false;

        if ($events !== null) {
            foreach ($events as $event) {
                $action = data_get($event, 'payload.action');
                if ($action === 'ARRIVED_PICKUP') {
                    $arrivedPickup = true;
                }
                if ($action === 'ARRIVED_DROPOFF') {
                    $arrivedDropoff = true;
                }
            }
        }

        return [
            'order_id' => $this->order_number,
            'id' => $this->id,
            'status' => $this->status,
            'status_ui' => $this->statusToUi((string) $this->status),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'pickup' => [
                'address' => $this->pickup_address,
                'lat' => (float) $this->pickup_latitude,
                'lng' => (float) $this->pickup_longitude,
            ],
            'dropoff' => [
                'address' => $this->dropoff_address,
                'lat' => (float) $this->dropoff_latitude,
                'lng' => (float) $this->dropoff_longitude,
            ],
            'distance_km' => (float) $this->distance_km,
            'delivery_fee' => (float) $this->delivery_fee,
            'currency' => $this->currency,
            'offer_expires_at' => optional($this->resource->getAttributes()['current_offer_expires_at'] ?? null)->toIso8601String(),
            'status_timestamps' => [
                'accepted_at' => optional($this->accepted_at)->toIso8601String(),
                'started_at' => optional($this->started_at)->toIso8601String(),
                'picked_up_at' => optional($this->picked_up_at)->toIso8601String(),
                'delivered_at' => optional($this->delivered_at)->toIso8601String(),
                'completed_at' => optional($this->completed_at)->toIso8601String(),
                'delivery_failed_at' => optional($this->delivery_failed_at)->toIso8601String(),
                'returned_to_merchant_at' => optional($this->returned_to_merchant_at)->toIso8601String(),
            ],
            'delivery_failure_code' => $this->delivery_failure_code,
            'delivery_failure_reason' => $this->delivery_failure_reason,
            'next_allowed_actions' => $this->nextActions((string) $this->status, $arrivedPickup, $arrivedDropoff),
            'arrived_pickup' => $arrivedPickup,
            'arrived_dropoff' => $arrivedDropoff,
        ];
    }

    private function statusToUi(string $status): string
    {
        return match ($status) {
            DeliveryOrderStatus::Offered->value => 'WAITING_ACCEPTANCE',
            DeliveryOrderStatus::Accepted->value,
            DeliveryOrderStatus::InProgress->value,
            DeliveryOrderStatus::PickedUp->value,
            DeliveryOrderStatus::ReturningToMerchant->value => 'ACTIVE',
            DeliveryOrderStatus::Completed->value,
            DeliveryOrderStatus::Delivered->value,
            DeliveryOrderStatus::ReturnedToMerchant->value => 'COMPLETED',
            DeliveryOrderStatus::Rejected->value => 'REJECTED',
            default => mb_strtoupper($status),
        };
    }

    private function nextActions(string $status, bool $arrivedPickup, bool $arrivedDropoff): array
    {
        return match ($status) {
            DeliveryOrderStatus::Accepted->value => [
                'GO_TO_PICKUP',
                'ARRIVED_PICKUP',
                'PICKUP_CONFIRMED',
            ],
            DeliveryOrderStatus::InProgress->value => $arrivedPickup
                ? ['PICKUP_CONFIRMED']
                : ['ARRIVED_PICKUP', 'PICKUP_CONFIRMED'],
            DeliveryOrderStatus::PickedUp->value => $arrivedDropoff
                ? ['DELIVERED_CONFIRMED', 'DELIVERY_FAILED']
                : ['GO_TO_DELIVERY', 'ARRIVED_DROPOFF', 'DELIVERED_CONFIRMED', 'DELIVERY_FAILED'],
            DeliveryOrderStatus::ReturningToMerchant->value => ['RETURNED_TO_MERCHANT'],
            default => [],
        };
    }
}
