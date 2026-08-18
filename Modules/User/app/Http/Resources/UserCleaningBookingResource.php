<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;

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

        $payload['discountAmount'] = $discountAmount;
        $payload['subtotalBeforeDiscount'] = (float) ($this->subtotal_before_discount ?? 0);
        $payload['travelFeePending'] = ! (bool) $this->is_pricing_final;

        return $payload;
    }
}
