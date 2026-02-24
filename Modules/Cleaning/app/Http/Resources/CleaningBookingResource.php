<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * @mixin CleaningBooking
 */
final class CleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'workerId' => $this->worker_id,
            'preferredWorkerId' => $this->preferred_worker_id,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'billingPolicyId' => $this->billing_policy_id,
            'bookingNumber' => $this->booking_number,
            'status' => $this->status?->value ?? $this->status,
            'propertyType' => $this->property_type,
            'propertyDetails' => $this->property_details,
            'locationName' => Arr::get($this->property_details, 'location_name') ?? Arr::get($this->property_details, 'address') ?? $this->property_type,
            'numberOfRooms' => Arr::get($this->property_details, 'bedrooms') ?? Arr::get($this->property_details, 'rooms'),
            'estimatedSqm' => $this->estimated_sqm,
            'estimatedHours' => $this->estimated_hours,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'totalHours' => (float) $this->total_hours,
            'basePrice' => (float) $this->base_price,
            'addonsTotal' => (float) $this->addons_total,
            'travelFee' => (float) $this->travel_fee,
            'cancellationFee' => (float) $this->cancellation_fee,
            'totalPrice' => (float) $this->total_price,
            'termsAccepted' => $this->terms_accepted,
            'workStartedAt' => $this->work_started_at?->toDateTimeString(),
            'workFinishedAt' => $this->work_finished_at?->toDateTimeString(),
            'customerConfirmedAt' => $this->customer_confirmed_at?->toDateTimeString(),
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'cancellationReason' => $this->cancellation_reason,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
            'worker' => $this->whenLoaded('worker', fn () => $this->worker ? [
                'id' => $this->worker->id,
                'firstName' => $this->worker->first_name,
            ] : null),
            'services' => $this->whenLoaded('services'),
            'addons' => $this->whenLoaded('addons'),
            'billingPolicy' => $this->whenLoaded('billingPolicy'),
            'timeWarnings' => $this->whenLoaded('timeWarnings'),
            'disputes' => $this->whenLoaded('disputes'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
