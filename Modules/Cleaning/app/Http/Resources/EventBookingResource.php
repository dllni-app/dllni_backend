<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Models\EventBooking;
use Modules\Cleaning\Services\CleaningOrderUrgencyService;

/**
 * @mixin EventBooking
 */
final class EventBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $urgency = app(CleaningOrderUrgencyService::class);
        $baseTitle = 'طلب مناسبة #'.$this->booking_number;
        $displayTitle = $urgency->displayTitle($baseTitle, $this->scheduled_date);
        $isHotOrder = $urgency->isHotOrder($this->scheduled_date);

        return [
            'id' => $this->id,
            'customerId' => $this->customer_id,
            'cancellationPolicyId' => $this->cancellation_policy_id,
            'billingPolicyId' => $this->billing_policy_id,
            'bookingNumber' => $this->booking_number,
            'displayTitle' => $displayTitle,
            'display_title' => $displayTitle,
            'isHotOrder' => $isHotOrder,
            'is_hot_order' => $isHotOrder,
            'urgencyLabel' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_LABEL : null,
            'urgency_label' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_LABEL : null,
            'urgencyPrefix' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_PREFIX : null,
            'urgency_prefix' => $isHotOrder ? CleaningOrderUrgencyService::HOT_ORDER_PREFIX : null,
            'status' => $this->status?->value ?? $this->status,
            'eventType' => $this->event_type?->value ?? $this->event_type,
            'guestCountMin' => $this->guest_count_min,
            'guestCountMax' => $this->guest_count_max,
            'genderPreference' => $this->gender_preference,
            'suggestedTeamSize' => $this->suggested_team_size,
            'scheduledDate' => $this->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => $this->scheduled_time,
            'totalHours' => (float) $this->total_hours,
            'basePrice' => (float) $this->base_price,
            'travelFee' => (float) $this->travel_fee,
            'totalPrice' => (float) $this->total_price,
            'termsAccepted' => $this->terms_accepted,
            'cancelledAt' => $this->cancelled_at?->toDateTimeString(),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ]),
            'services' => $this->whenLoaded('services'),
            'billingPolicy' => $this->whenLoaded('billingPolicy'),
            'timeWarnings' => $this->whenLoaded('timeWarnings'),
            'disputes' => $this->whenLoaded('disputes'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
