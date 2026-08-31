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
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    /**
     * Apply cleaning coupons against the full customer order total, then fund the
     * discount from the administration margin first. Only the amount that remains
     * after administration reaches zero may reduce the service share. Travel is
     * never reduced as a component.
     *
     * Example: service 960 + admin 240 = order total 1,200. A 10% coupon is 120.
     * Admin becomes 120, service remains 960, and the customer pays 1,080.
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

        $eligibleAmount = round($grossServiceAmount + $grossAdminMargin, 2);
        if ($coupon->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE) {
            // The advertised percentage is calculated from the full amount the
            // customer would pay before the coupon (service + travel + admin).
            $discountAmount = $this->couponEligibility->calculateDiscount($coupon, $grossTotal);
        } else {
            $discountAmount = min(max(0.0, (float) $coupon->discount_value), $grossTotal);
        }

        // The discount is funded by admin first, then service. Travel is never
        // reduced, so the maximum fundable discount is admin + service.
        $discountAmount = round(min(max(0.0, $discountAmount), $eligibleAmount), 2);

        $adminDiscountAmount = round(min($discountAmount, $grossAdminMargin), 2);
        $workerDiscountAmount = round(max(0.0, $discountAmount - $adminDiscountAmount), 2);
        $adminNet = round(max(0.0, $grossAdminMargin - $adminDiscountAmount), 2);
        $serviceNet = round(max(0.0, $grossServiceAmount - $workerDiscountAmount), 2);
        $travelNet = $grossTravelFee;
        $serviceNetFactor = $grossServiceAmount > 0.0
            ? max(0.0, min(1.0, $serviceNet / $grossServiceAmount))
            : 1.0;
        $adminNetFactor = $grossAdminMargin > 0.0
            ? max(0.0, min(1.0, $adminNet / $grossAdminMargin))
            : 1.0;
        $discountRatio = $grossTotal > 0.0 ? min(1.0, $discountAmount / $grossTotal) : 0.0;

        return [
            'grossServiceAmount' => $grossServiceAmount,
            'grossTravelFee' => $grossTravelFee,
            'grossAdminMargin' => $grossAdminMargin,
            'grossTotal' => $grossTotal,
            'discountAmount' => $discountAmount,
            'discountRatio' => $discountRatio,
            'netFactor' => max(0.0, 1.0 - $discountRatio),
            'allocatedDiscountAmount' => $discountAmount,
            'platformSubsidyAmount' => 0.0,
            'adminDiscountAmount' => $adminDiscountAmount,
            'workerDiscountAmount' => $workerDiscountAmount,
            'customerWorkerNetFactor' => $serviceNetFactor,
            'assignmentWorkerNetFactor' => $serviceNetFactor,
            'adminNetFactor' => $adminNetFactor,
            'serviceAmount' => $serviceNet,
            'travelFee' => $travelNet,
            'adminMargin' => $adminNet,
            'totalPrice' => round($serviceNet + $travelNet + $adminNet, 2),
        ];
    }

    /**
     * Return the dashboard breakdown using the current admin-first coupon rule.
     */
    public function storedBreakdown(CleaningBooking $booking): ?array
    {
        $couponId = (int) ($booking->platform_coupon_id ?? 0);
        if ($couponId <= 0) {
            return null;
        }

        $coupon = PlatformCoupon::query()->find($couponId);
        if (! $coupon instanceof PlatformCoupon) {
            return null;
        }

        $grossServiceAmount = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );
        $storedDiscount = max(0.0, (float) ($booking->discount_amount ?? 0));
        $grossTotal = round(max(
            0.0,
            (float) ($booking->subtotal_before_discount
                ?? ((float) ($booking->total_price ?? 0) + $storedDiscount)),
        ), 2);
        $grossTravelFee = round(max(0.0, (float) ($booking->travel_fee ?? 0)), 2);
        $grossAdminMargin = round(max(0.0, $grossTotal - $grossServiceAmount - $grossTravelFee), 2);

        if (! (bool) $booking->is_pricing_final && $grossAdminMargin <= 0.0) {
            $grossAdminMargin = (float) $this->pricingCalculator
                ->provisional($grossServiceAmount, 0.0)['adminMargin'];
        }

        return $this->allocation(
            $coupon,
            $grossServiceAmount,
            $grossTravelFee,
            $grossAdminMargin,
        );
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

        $previousFactors = $this->previousDiscountFactors($booking, $coupon);
        $couponWasJustAttached = $booking->isDirty('platform_coupon_id')
            || empty($booking->getOriginal('platform_coupon_id'));
        $assignmentPricingWasRecalculated = $booking->isDirty([
            'base_price',
            'addons_total',
            'travel_fee',
            'admin_margin_amount',
            'is_pricing_final',
        ]);

        // base_price and addons_total intentionally remain gross snapshots. They
        // are used by the team pricing engine whenever workers are recalculated.
        $grossServiceAmount = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );
        $grossTravelFee = round(max(0.0, (float) ($booking->travel_fee ?? 0)), 2);
        $currentAdminMargin = round(max(0.0, (float) ($booking->admin_margin_amount ?? 0)), 2);
        if (! (bool) $booking->is_pricing_final && $currentAdminMargin <= 0.0) {
            // Open-count bookings are initially stored without an admin margin
            // until a worker is assigned. Coupons still consume the configured
            // provisional administration margin first, otherwise the pending
            // user/worker APIs would incorrectly deduct the coupon from service.
            $grossAdminMargin = (float) $this->pricingCalculator
                ->provisional($grossServiceAmount, 0.0)['adminMargin'];
        } elseif ($couponWasJustAttached || $booking->isDirty('admin_margin_amount')) {
            $grossAdminMargin = $currentAdminMargin;
        } else {
            // The subtotal snapshot is the reliable source for the original admin
            // margin, including the case where the coupon reduced net admin to 0.
            $originalGrossTotal = max(0.0, (float) ($booking->getOriginal('subtotal_before_discount') ?? 0));
            $originalServiceAmount = round(
                max(0.0, (float) ($booking->getOriginal('base_price') ?? 0))
                + max(0.0, (float) ($booking->getOriginal('addons_total') ?? 0)),
                2,
            );
            $originalTravelFee = round(max(0.0, (float) ($booking->getOriginal('travel_fee') ?? 0)), 2);
            $grossAdminMargin = $originalGrossTotal > 0.0
                ? round(max(0.0, $originalGrossTotal - $originalServiceAmount - $originalTravelFee), 2)
                : $currentAdminMargin;
        }

        $allocation = $this->allocation(
            $coupon,
            $grossServiceAmount,
            $grossTravelFee,
            $grossAdminMargin,
        );

        $this->applyToWorkerAssignments(
            $booking,
            (float) $allocation['assignmentWorkerNetFactor'],
            (float) $allocation['adminNetFactor'],
            $previousFactors['assignmentWorkerNetFactor'],
            $previousFactors['adminNetFactor'],
            $couponWasJustAttached || $assignmentPricingWasRecalculated,
        );

        $booking->travel_fee = $allocation['travelFee'];
        $booking->admin_margin_amount = $allocation['adminMargin'];
        $booking->subtotal_before_discount = $allocation['grossTotal'];
        $booking->discount_amount = $allocation['discountAmount'];
        $booking->total_price = $allocation['totalPrice'];

        $this->syncRedemptionSnapshot($booking, $allocation);
    }

    /**
     * @return array{customerWorkerNetFactor: float, assignmentWorkerNetFactor: float, adminNetFactor: float}
     */
    private function previousDiscountFactors(CleaningBooking $booking, PlatformCoupon $coupon): array
    {
        $grossTotal = max(0.0, (float) ($booking->getOriginal('subtotal_before_discount') ?? 0));
        $storedDiscount = max(0.0, (float) ($booking->getOriginal('discount_amount') ?? 0));

        if ($grossTotal <= 0.0 || $storedDiscount <= 0.0) {
            return $this->fullFactors();
        }

        $grossServiceAmount = round(
            max(0.0, (float) ($booking->getOriginal('base_price') ?? 0))
            + max(0.0, (float) ($booking->getOriginal('addons_total') ?? 0)),
            2,
        );
        $grossTravelFee = round(max(0.0, (float) ($booking->getOriginal('travel_fee') ?? 0)), 2);
        $netAdminMargin = round(max(0.0, (float) ($booking->getOriginal('admin_margin_amount') ?? 0)), 2);
        $grossAdminMargin = round(max(0.0, $grossTotal - $grossServiceAmount - $grossTravelFee), 2);
        $currentAllocation = $this->allocation(
            $coupon,
            $grossServiceAmount,
            $grossTravelFee,
            $grossAdminMargin,
        );

        $assignmentFactor = (float) $currentAllocation['assignmentWorkerNetFactor'];
        $expectedDiscount = (float) $currentAllocation['discountAmount'];
        $expectedAdmin = (float) $currentAllocation['adminMargin'];
        $expectedTotal = (float) $currentAllocation['totalPrice'];
        $storedTotal = max(0.0, (float) ($booking->getOriginal('total_price') ?? 0));

        $legacyFactor = $grossTotal > 0.0
            ? max(0.0, min(1.0, ($grossTotal - $storedDiscount) / $grossTotal))
            : 1.0;

        $bookingPricingDiffersFromCurrentRule =
            abs($storedDiscount - $expectedDiscount) > 0.02
            || abs($netAdminMargin - $expectedAdmin) > 0.02
            || abs($storedTotal - $expectedTotal) > 0.02;

        // Historical coupon versions reduced assignment service/travel by the
        // customer's overall coupon factor. Restore that gross share only when
        // the persisted row still looks legacy. This also keeps reruns idempotent.
        if (
            $legacyFactor < 1.0
            && (
                (
                    abs($storedDiscount - $expectedDiscount) <= 0.02
                    && $bookingPricingDiffersFromCurrentRule
                )
                || $this->assignmentsMatchLegacyServiceFactor(
                    $booking,
                    $grossServiceAmount,
                    $legacyFactor,
                    $assignmentFactor,
                )
            )
        ) {
            $assignmentFactor = $legacyFactor;
        }

        return [
            'customerWorkerNetFactor' => 1.0,
            'assignmentWorkerNetFactor' => $assignmentFactor,
            'adminNetFactor' => $grossAdminMargin > 0.0
                ? max(0.0, min(1.0, $netAdminMargin / $grossAdminMargin))
                : 1.0,
        ];
    }

    private function assignmentsMatchLegacyServiceFactor(
        CleaningBooking $booking,
        float $grossServiceAmount,
        float $legacyFactor,
        float $currentRuleFactor,
    ): bool {
        if (
            $grossServiceAmount <= 0.0
            || abs($legacyFactor - $currentRuleFactor) <= 0.0001
        ) {
            return false;
        }

        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->get(['service_share_amount']);

        if ($assignments->isEmpty()) {
            return false;
        }

        // When the team is fully represented, the sum is a reliable fingerprint
        // of the old global factor. For partial teams we avoid guessing.
        $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));
        if ($assignments->count() < $requiredWorkers) {
            return false;
        }

        $storedServiceTotal = round((float) $assignments->sum(
            static fn (CleaningBookingWorkerAssignment $assignment): float =>
                max(0.0, (float) ($assignment->service_share_amount ?? 0)),
        ), 2);
        $legacyServiceTotal = round($grossServiceAmount * $legacyFactor, 2);
        $currentServiceTotal = round($grossServiceAmount * $currentRuleFactor, 2);

        return abs($storedServiceTotal - $legacyServiceTotal) <= 1.0
            && abs($storedServiceTotal - $currentServiceTotal) > 1.0;
    }

    /** @return array{customerWorkerNetFactor: float, assignmentWorkerNetFactor: float, adminNetFactor: float} */
    private function fullFactors(): array
    {
        return [
            'customerWorkerNetFactor' => 1.0,
            'assignmentWorkerNetFactor' => 1.0,
            'adminNetFactor' => 1.0,
        ];
    }

    private function applyToWorkerAssignments(
        CleaningBooking $booking,
        float $assignmentWorkerNetFactor,
        float $adminNetFactor,
        float $previousAssignmentWorkerNetFactor,
        float $previousAdminNetFactor,
        bool $currentAssignmentValuesAreGross,
    ): void {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->get();

        foreach ($assignments as $assignment) {
            $grossServiceShare = $this->assignmentGrossValue(
                (float) ($assignment->service_share_amount ?? 0),
                $previousAssignmentWorkerNetFactor,
                $currentAssignmentValuesAreGross,
            );
            $grossTravelFee = $this->assignmentGrossValue(
                (float) ($assignment->travel_fee ?? 0),
                $previousAssignmentWorkerNetFactor,
                $currentAssignmentValuesAreGross,
            );
            $grossAdminMargin = $this->assignmentGrossValue(
                (float) ($assignment->admin_margin_amount ?? 0),
                $previousAdminNetFactor,
                $currentAssignmentValuesAreGross,
            );

            // Keep service/travel at their gross worker share while the coupon
            // fits inside the administration margin. Only the excess coupon
            // reduces the service share; travel is never discounted.
            $serviceShare = round($grossServiceShare * $assignmentWorkerNetFactor, 2);
            $travelFee = round($grossTravelFee, 2);
            $adminMargin = round($grossAdminMargin * $adminNetFactor, 2);

            $assignment->forceFill([
                'service_share_amount' => $serviceShare,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                // Administration margin is added to the customer price separately;
                // it is not deducted again from the worker's service/travel share.
                'worker_amount' => round(max(0.0, $serviceShare + $travelFee), 2),
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
