<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Services\UserCleaningOrderEstimationService;

/** @mixin CleaningBooking */
final class UserCleaningBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = (new CleaningBookingResource($this->resource))->toArray($request);

        $discountAmount = max(0.0, (float) ($this->discount_amount ?? 0));
        $extensionFeeTotal = max(0.0, (float) ($this->extension_fee_total ?? 0));
        $bookingBasePrice = max(0.0, (float) ($this->base_price ?? 0));
        $bookingAdminMargin = max(0.0, (float) ($this->admin_margin_amount ?? 0));

        // The user app intentionally presents the administration margin inside
        // "قيمة الخدمة" rather than as a separate line. Keep order details
        // consistent with the estimate/confirmation screen. Coupon and extension
        // flows keep their existing breakdown until their dedicated rows are
        // rendered by clients.
        if ($discountAmount <= 0.0 && $extensionFeeTotal <= 0.0) {
            $displayServicePrice = round($bookingBasePrice + $bookingAdminMargin, 2);
            $payload['basePrice'] = $displayServicePrice;
            $payload['servicePrice'] = $displayServicePrice;
            $payload['service_price'] = $displayServicePrice;
        }

        $workerCount = max(1, (int) ($this->number_of_workers ?? 1));
        $bookingEstimatedHours = $this->estimated_hours !== null
            ? (float) $this->estimated_hours
            : null;
        $bookingTotalHours = (float) ($this->total_hours ?? $bookingEstimatedHours ?? 0);
        $isEventAssistance = (string) $this->property_type === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;

        // Regular cleaning estimation is calculated as the total sequential work
        // for all rooms. When multiple workers execute the order in parallel, the
        // customer-facing elapsed duration is the estimated time divided by the
        // number of workers. Event assistance is different: its configured hours
        // are the actual event duration, so they must not be divided.
        if (! $isEventAssistance && $workerCount > 1) {
            if ($bookingEstimatedHours !== null) {
                $payload['estimatedHours'] = round($bookingEstimatedHours / $workerCount, 2);
            }
            $payload['totalHours'] = round($bookingTotalHours / $workerCount, 2);
        }

        $payload['bookingEstimatedHours'] = $bookingEstimatedHours;
        $payload['bookingTotalHours'] = $bookingTotalHours;
        $payload['durationWorkerCount'] = $workerCount;
        $payload['discountAmount'] = $discountAmount;
        $payload['subtotalBeforeDiscount'] = (float) ($this->subtotal_before_discount ?? 0);
        $payload['travelFeePending'] = ! (bool) $this->is_pricing_final;

        return $payload;
    }
}
