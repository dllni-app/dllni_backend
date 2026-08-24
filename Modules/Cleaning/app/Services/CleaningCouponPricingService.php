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
    private const FACTOR_EPSILON = 0.0001;

    public function __construct(
        private readonly PlatformCouponEligibilityService $couponEligibility,
    ) {}

    /**
     * Charge the coupon percentage to the administration percentage first.
     * Only percentage points above the administration share reduce worker net
     * earnings. The customer still receives the exact coupon discount that was
     * already calculated for the order.
     *
     * Fixed-value coupons keep the same amount-based fallback: administration
     * absorbs the fixed discount first and the worker absorbs only the excess.
     *
     * @return array{
     *     grossServiceAmount: float,
     *     grossTravelFee: float,
     *     grossAdminMargin: float,
     *     grossTotal: float,
     *     discountAmount: float,
     *     discountRatio: float,
     *     netFactor: float,
     *     allocatedDiscountAmount: float,
     *     platformSubsidyAmount: float,
     *     adminDiscountAmount: float,
     *     workerDiscountAmount: float,
     *     customerWorkerNetFactor: float,
     *     assignmentWorkerNetFactor: float,
     *     adminNetFactor: float,
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
        $grossWorkerAmount = round($grossServiceAmount + $grossTravelFee, 2);
        $grossTotal = round($grossWorkerAmount + $grossAdminMargin, 2);
        $discountAmount = $grossTotal > 0
            ? $this->couponEligibility->calculateDiscount($coupon, $grossTotal)
            : 0.0;
        $discountAmount = round(min(max(0.0, $discountAmount), $grossTotal), 2);
        $discountRatio = $grossTotal > 0 ? min(1.0, $discountAmount / $grossTotal) : 0.0;
        $netFactor = max(0.0, 1.0 - $discountRatio);

        $allocatedDiscountAmount = $this->allocatedDiscountAmount(
            $coupon,
            $grossServiceAmount,
            $discountAmount,
        );
        $adminDiscountAmount = round(min($allocatedDiscountAmount, $grossAdminMargin), 2);
        $workerDiscountAmount = round(max(0.0, $allocatedDiscountAmount - $adminDiscountAmount), 2);
        $platformSubsidyAmount = round(max(0.0, $discountAmount - $allocatedDiscountAmount), 2);
        $adminNet = round(max(0.0, $grossAdminMargin - $adminDiscountAmount), 2);

        $customerWorkerNetFactor = $grossWorkerAmount > 0
            ? max(0.0, min(1.0, ($grossWorkerAmount - $workerDiscountAmount) / $grossWorkerAmount))
            : 1.0;
        $assignmentWorkerNetFactor = $grossWorkerAmount > 0
            ? max(0.0, min(1.0, ($grossWorkerAmount - $allocatedDiscountAmount) / $grossWorkerAmount))
            : 1.0;
        $adminNetFactor = $grossAdminMargin > 0
            ? max(0.0, min(1.0, $adminNet / $grossAdminMargin))
            : 1.0;

        $serviceNet = round($grossServiceAmount * $customerWorkerNetFactor, 2);
        $travelNet = round($grossTravelFee * $customerWorkerNetFactor, 2);
        $allocatedComponentTotal = round(max(0.0, $grossTotal - $allocatedDiscountAmount), 2);

        // Keep the administration/worker allocation exact after rounding. Any
        // remaining customer discount is platform-funded and intentionally does
        // not reduce either the administration percentage or worker earnings.
        $roundingDelta = round($allocatedComponentTotal - ($serviceNet + $travelNet + $adminNet), 2);
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
            'allocatedDiscountAmount' => $allocatedDiscountAmount,
            'platformSubsidyAmount' => $platformSubsidyAmount,
            'adminDiscountAmount' => $adminDiscountAmount,
            'workerDiscountAmount' => $workerDiscountAmount,
            'customerWorkerNetFactor' => $customerWorkerNetFactor,
            'assignmentWorkerNetFactor' => $assignmentWorkerNetFactor,
            'adminNetFactor' => $adminNetFactor,
            'serviceAmount' => $serviceNet,
            'travelFee' => $travelNet,
            'adminMargin' => $adminNet,
            'totalPrice' => round(max(0.0, $grossTotal - $discountAmount), 2),
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

        $previousFactors = $this->previousDiscountFactors($booking, $coupon);
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
            previousNetFactor: $previousFactors['customerWorkerNetFactor'],
            treatCurrentAsGross: $couponWasJustAttached || $pricingWasRecalculated,
        );
        $grossAdminMargin = $this->grossStoredComponent(
            currentValue: (float) ($booking->admin_margin_amount ?? 0),
            fieldIsDirty: $booking->isDirty('admin_margin_amount'),
            previousNetFactor: $previousFactors['adminNetFactor'],
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
            (float) $allocation['assignmentWorkerNetFactor'],
            (float) $allocation['adminNetFactor'],
            $previousFactors['assignmentWorkerNetFactor'],
            $previousFactors['adminNetFactor'],
            $couponWasJustAttached || $pricingWasRecalculated,
        );

        $booking->travel_fee = $allocation['travelFee'];
        $booking->admin_margin_amount = $allocation['adminMargin'];
        $booking->subtotal_before_discount = $allocation['grossTotal'];
        $booking->discount_amount = $allocation['discountAmount'];
        $booking->total_price = $allocation['totalPrice'];

        $this->syncRedemptionSnapshot($booking, $allocation);
    }

    /**
     * The order discount can include administration/travel markup because that
     * is how the current customer total is quoted. For percentage coupons, the
     * financial burden still follows percentage points on the gross service
     * value: admin percentage first, then worker percentage. The difference is
     * platform-funded so the customer-facing discount never changes.
     */
    private function allocatedDiscountAmount(
        PlatformCoupon $coupon,
        float $grossServiceAmount,
        float $discountAmount,
    ): float {
        if ($coupon->discount_type !== PlatformCoupon::DISCOUNT_PERCENTAGE || $grossServiceAmount <= 0.0) {
            return round($discountAmount, 2);
        }

        $percentageAmount = $grossServiceAmount * (max(0.0, (float) $coupon->discount_value) / 100);

        return round(min($discountAmount, max(0.0, $percentageAmount)), 2);
    }

    /**
     * @return array{customerWorkerNetFactor: float, assignmentWorkerNetFactor: float, adminNetFactor: float}
     */
    private function previousDiscountFactors(CleaningBooking $booking, PlatformCoupon $coupon): array
    {
        $grossTotal = max(0.0, (float) ($booking->getOriginal('subtotal_before_discount') ?? 0));
        $discount = max(0.0, (float) ($booking->getOriginal('discount_amount') ?? 0));

        if ($grossTotal <= 0.0 || $discount <= 0.0) {
            return $this->fullFactors();
        }

        $grossServiceAmount = round(
            max(0.0, (float) ($booking->getOriginal('base_price') ?? 0))
            + max(0.0, (float) ($booking->getOriginal('addons_total') ?? 0)),
            2,
        );
        $netTravelFee = max(0.0, (float) ($booking->getOriginal('travel_fee') ?? 0));
        $netAdminMargin = max(0.0, (float) ($booking->getOriginal('admin_margin_amount') ?? 0));

        // Existing bookings may have been saved by the old proportional coupon
        // allocator. Detect that shape once so the first recalculation after this
        // deployment restores the original gross values correctly.
        $legacyFactors = $this->legacyPreviousDiscountFactors(
            $grossTotal,
            $discount,
            $grossServiceAmount,
            $netTravelFee,
            $netAdminMargin,
        );
        if ($legacyFactors !== null) {
            return $legacyFactors;
        }

        $allocatedDiscountAmount = $this->allocatedDiscountAmount(
            $coupon,
            $grossServiceAmount,
            $discount,
        );
        $grossNonServiceAmount = max(0.0, $grossTotal - $grossServiceAmount);

        if ($netAdminMargin > 0.0) {
            // A remaining admin margin means the allocated coupon burden never
            // reached the worker share.
            $grossAdminMargin = min(
                $grossNonServiceAmount,
                $netAdminMargin + $allocatedDiscountAmount,
            );
        } else {
            // When admin is exhausted, solve the previous gross admin amount
            // from the stored net travel and the known allocated coupon burden.
            $componentTotalAfterAllocation = max(0.0, $grossTotal - $allocatedDiscountAmount);
            $denominator = $componentTotalAfterAllocation - $netTravelFee;

            if (abs($denominator) > self::FACTOR_EPSILON) {
                $grossAdminMargin = (
                    ($componentTotalAfterAllocation * $grossNonServiceAmount)
                    - ($netTravelFee * $grossTotal)
                ) / $denominator;
            } else {
                $grossAdminMargin = $grossNonServiceAmount - $netTravelFee;
            }

            $grossAdminMargin = min(
                $grossNonServiceAmount,
                max(0.0, $grossAdminMargin),
            );
        }

        $grossTravelFee = max(0.0, $grossNonServiceAmount - $grossAdminMargin);
        $grossWorkerAmount = max(0.0, $grossServiceAmount + $grossTravelFee);
        $adminDiscountAmount = min($allocatedDiscountAmount, $grossAdminMargin);
        $workerDiscountAmount = max(0.0, $allocatedDiscountAmount - $adminDiscountAmount);
        $adminNet = max(0.0, $grossAdminMargin - $adminDiscountAmount);

        return [
            'customerWorkerNetFactor' => $grossWorkerAmount > 0.0
                ? max(0.0, min(1.0, ($grossWorkerAmount - $workerDiscountAmount) / $grossWorkerAmount))
                : 1.0,
            'assignmentWorkerNetFactor' => $grossWorkerAmount > 0.0
                ? max(0.0, min(1.0, ($grossWorkerAmount - $allocatedDiscountAmount) / $grossWorkerAmount))
                : 1.0,
            'adminNetFactor' => $grossAdminMargin > 0.0
                ? max(0.0, min(1.0, $adminNet / $grossAdminMargin))
                : 1.0,
        ];
    }

    /**
     * @return array{customerWorkerNetFactor: float, assignmentWorkerNetFactor: float, adminNetFactor: float}|null
     */
    private function legacyPreviousDiscountFactors(
        float $grossTotal,
        float $discount,
        float $grossServiceAmount,
        float $netTravelFee,
        float $netAdminMargin,
    ): ?array {
        $legacyNetFactor = max(0.0, min(1.0, 1.0 - ($discount / $grossTotal)));
        if ($legacyNetFactor <= self::FACTOR_EPSILON) {
            if ($netTravelFee <= 0.01 && $netAdminMargin <= 0.01) {
                return [
                    'customerWorkerNetFactor' => 0.0,
                    'assignmentWorkerNetFactor' => 0.0,
                    'adminNetFactor' => 0.0,
                ];
            }

            return null;
        }

        $legacyGrossTravelFee = $netTravelFee / $legacyNetFactor;
        $legacyGrossAdminMargin = max(0.0, $grossTotal - $grossServiceAmount - $legacyGrossTravelFee);
        $expectedNetAdminMargin = round($legacyGrossAdminMargin * $legacyNetFactor, 2);

        if (abs($expectedNetAdminMargin - $netAdminMargin) > 0.02) {
            return null;
        }

        return [
            'customerWorkerNetFactor' => $legacyNetFactor,
            'assignmentWorkerNetFactor' => $legacyNetFactor,
            'adminNetFactor' => $legacyNetFactor,
        ];
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

            // Assignment gross is reduced by the allocated coupon percentage
            // while admin margin is reduced by the admin-funded percentage. The
            // difference is therefore exactly the worker-funded excess only.
            $serviceShare = round($grossServiceShare * $assignmentWorkerNetFactor, 2);
            $travelFee = round($grossTravelFee * $assignmentWorkerNetFactor, 2);
            $adminMargin = round($grossAdminMargin * $adminNetFactor, 2);

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
