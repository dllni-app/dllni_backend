<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Services\Coupons\PlatformCouponEligibilityService;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningCouponPricingService
{
    public function __construct(
        private readonly PlatformCouponEligibilityService $couponEligibility,
    ) {}

    /**
     * Apply the coupon proportionally to every customer-facing cleaning price
     * component: service value, travel fee, and administration margin.
     *
     * @return array{
     *     grossServiceAmount: float,
     *     grossTravelFee: float,
     *     grossAdminMargin: float,
     *     grossTotal: float,
     *     discountAmount: float,
     *     discountRatio: float,
     *     netFactor: float,
     *     serviceAmount: float,
     *     travelFee: float,
     *     adminMargin: float,
     *     totalPrice: float
     * }
     */
    public function allocation(
        PlatformCoupon $coupon,
        float $serviceAmount,
        float $travelFee,
        float $adminMargin,
    ): array {
        $grossServiceAmount = round(max(0.0, $serviceAmount), 2);
        $grossTravelFee = round(max(0.0, $travelFee), 2);
        $grossAdminMargin = round(max(0.0, $adminMargin), 2);
        $grossTotal = round($grossServiceAmount + $grossTravelFee + $grossAdminMargin, 2);
        $discountAmount = $grossTotal > 0
            ? $this->couponEligibility->calculateDiscount($coupon, $grossTotal)
            : 0.0;
        $discountAmount = round(min(max(0.0, $discountAmount), $grossTotal), 2);
        $discountRatio = $grossTotal > 0 ? min(1.0, $discountAmount / $grossTotal) : 0.0;
        $netFactor = max(0.0, 1.0 - $discountRatio);

        $serviceNet = round($grossServiceAmount * $netFactor, 2);
        $travelNet = round($grossTravelFee * $netFactor, 2);
        $adminNet = round($grossAdminMargin * $netFactor, 2);
        $targetTotal = round(max(0.0, $grossTotal - $discountAmount), 2);

        // Keep the breakdown equal to the exact coupon total after rounding.
        $roundingDelta = round($targetTotal - ($serviceNet + $travelNet + $adminNet), 2);
        if ($roundingDelta !== 0.0) {
            if ($grossServiceAmount > 0) {
                $serviceNet = round(max(0.0, $serviceNet + $roundingDelta), 2);
            } elseif ($grossTravelFee > 0) {
                $travelNet = round(max(0.0, $travelNet + $roundingDelta), 2);
            } else {
                $adminNet = round(max(0.0, $adminNet + $roundingDelta), 2);
            }
        }

        return [
            'grossServiceAmount' => $grossServiceAmount,
            'grossTravelFee' => $grossTravelFee,
            'grossAdminMargin' => $grossAdminMargin,
            'grossTotal' => $grossTotal,
            'discountAmount' => $discountAmount,
            'discountRatio' => $discountRatio,
            'netFactor' => $netFactor,
            'serviceAmount' => $serviceNet,
            'travelFee' => $travelNet,
            'adminMargin' => $adminNet,
            'totalPrice' => $targetTotal,
        ];
    }

    /**
     * Mutate a booking before it is saved so coupon discounts stay synchronized
     * when worker assignment recalculates travel and administration amounts.
     */
    public function applyBeforeSave(CleaningBooking $booking): void
    {
        $couponId = (int) ($booking->platform_coupon_id ?? 0);
        if ($couponId <= 0) {
            return;
        }

        $coupon = PlatformCoupon::query()->find($couponId);
        if (! $coupon instanceof PlatformCoupon) {
            return;
        }

        $previousNetFactor = $this->previousNetFactor($booking);
        $couponWasJustAttached = $booking->isDirty('platform_coupon_id')
            || empty($booking->getOriginal('platform_coupon_id'));
        $pricingWasRecalculated = $booking->isDirty([
            'travel_fee',
            'admin_margin_amount',
            'total_price',
            'is_pricing_final',
        ]);

        // base_price and addons_total intentionally remain gross snapshots. They
        // are used by the team pricing engine whenever workers are recalculated.
        $grossServiceAmount = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );
        $grossTravelFee = $this->grossStoredComponent(
            currentValue: (float) ($booking->travel_fee ?? 0),
            fieldIsDirty: $booking->isDirty('travel_fee'),
            previousNetFactor: $previousNetFactor,
            treatCurrentAsGross: $couponWasJustAttached || $pricingWasRecalculated,
        );
        $grossAdminMargin = $this->grossStoredComponent(
            currentValue: (float) ($booking->admin_margin_amount ?? 0),
            fieldIsDirty: $booking->isDirty('admin_margin_amount'),
            previousNetFactor: $previousNetFactor,
            treatCurrentAsGross: $couponWasJustAttached || $pricingWasRecalculated,
        );

        $allocation = $this->allocation(
            $coupon,
            $grossServiceAmount,
            $grossTravelFee,
            $grossAdminMargin,
        );

        $this->applyToWorkerAssignments(
            $booking,
            (float) $allocation['netFactor'],
            $previousNetFactor,
            $couponWasJustAttached || $pricingWasRecalculated,
        );

        $booking->travel_fee = $allocation['travelFee'];
        $booking->admin_margin_amount = $allocation['adminMargin'];
        $booking->subtotal_before_discount = $allocation['grossTotal'];
        $booking->discount_amount = $allocation['discountAmount'];
        $booking->total_price = $allocation['totalPrice'];

        $this->syncRedemptionSnapshot($booking, $allocation);
    }

    private function previousNetFactor(CleaningBooking $booking): float
    {
        $gross = (float) ($booking->getOriginal('subtotal_before_discount') ?? 0);
        $discount = (float) ($booking->getOriginal('discount_amount') ?? 0);

        if ($gross <= 0.0 || $discount <= 0.0) {
            return 1.0;
        }

        return max(0.0, min(1.0, 1.0 - ($discount / $gross)));
    }

    private function grossStoredComponent(
        float $currentValue,
        bool $fieldIsDirty,
        float $previousNetFactor,
        bool $treatCurrentAsGross,
    ): float {
        $currentValue = max(0.0, $currentValue);

        if ($fieldIsDirty || $treatCurrentAsGross || $previousNetFactor >= 1.0) {
            return round($currentValue, 2);
        }

        if ($previousNetFactor <= 0.0) {
            return 0.0;
        }

        return round($currentValue / $previousNetFactor, 2);
    }

    private function applyToWorkerAssignments(
        CleaningBooking $booking,
        float $netFactor,
        float $previousNetFactor,
        bool $currentAssignmentValuesAreGross,
    ): void {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->get();

        foreach ($assignments as $assignment) {
            $grossServiceShare = $this->assignmentGrossValue(
                (float) ($assignment->service_share_amount ?? 0),
                $previousNetFactor,
                $currentAssignmentValuesAreGross,
            );
            $grossTravelFee = $this->assignmentGrossValue(
                (float) ($assignment->travel_fee ?? 0),
                $previousNetFactor,
                $currentAssignmentValuesAreGross,
            );
            $grossAdminMargin = $this->assignmentGrossValue(
                (float) ($assignment->admin_margin_amount ?? 0),
                $previousNetFactor,
                $currentAssignmentValuesAreGross,
            );

            $serviceShare = round($grossServiceShare * $netFactor, 2);
            $travelFee = round($grossTravelFee * $netFactor, 2);
            $adminMargin = round($grossAdminMargin * $netFactor, 2);

            $assignment->forceFill([
                'service_share_amount' => $serviceShare,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'worker_amount' => round(max(0.0, $serviceShare + $travelFee - $adminMargin), 2),
            ])->saveQuietly();
        }
    }

    private function assignmentGrossValue(
        float $currentValue,
        float $previousNetFactor,
        bool $currentValueIsGross,
    ): float {
        $currentValue = max(0.0, $currentValue);

        if ($currentValueIsGross || $previousNetFactor >= 1.0) {
            return round($currentValue, 2);
        }

        if ($previousNetFactor <= 0.0) {
            return 0.0;
        }

        return round($currentValue / $previousNetFactor, 2);
    }

    /** @param array<string, float> $allocation */
    private function syncRedemptionSnapshot(CleaningBooking $booking, array $allocation): void
    {
        PlatformCouponRedemption::query()
            ->where('platform_coupon_id', (int) $booking->platform_coupon_id)
            ->where('section', PlatformCoupon::SECTION_CLEANING)
            ->where('order_type', $booking->getMorphClass())
            ->where('order_id', $booking->getKey())
            ->update([
                'subtotal' => $allocation['grossTotal'],
                'discount_amount' => $allocation['discountAmount'],
            ]);
    }
}
