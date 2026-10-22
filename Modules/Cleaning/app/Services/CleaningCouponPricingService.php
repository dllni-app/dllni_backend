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
     * Apply cleaning coupons to the service charge (including its platform margin),
     * discount from the administration margin first. Only the amount that remains
     * after administration reaches zero may reduce the service share. Travel is
     * never reduced as a component.
     *
     * Travel never increases the coupon discount. Included margin is not added twice.
     * Example: commission-inclusive service 1,500 + travel 300, with a 375 margin,
     * receives a 150 discount; admin receives 225 and worker receives 1,125 + 300.
     * Admin becomes 120, service remains 960, and the customer pays 1,080.
     */
    public function allocation(
        PlatformCoupon $coupon,
        float $serviceAmount,
        float $travelFee,
        float $adminMargin,
        float $includedAdminMargin = 0.0,
    ): array {
        $customerServiceAmount = round(max(0.0, $serviceAmount), 2);
        $grossTravelFee = round(max(0.0, $travelFee), 2);
        $grossAdminMargin = round(max(0.0, $adminMargin), 2);
        $includedAdminMargin = round(min($customerServiceAmount, $grossAdminMargin, max(0.0, $includedAdminMargin)), 2);
        // The minimum-price service charge already contains this portion of the margin.
        $grossServiceAmount = round($customerServiceAmount - $includedAdminMargin, 2);
        $grossTotal = round($grossServiceAmount + $grossTravelFee + $grossAdminMargin, 2);

        $eligibleAmount = round($grossServiceAmount + $grossAdminMargin, 2);
        if ($coupon->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE) {
            // Percentage discounts apply to cleaning only, never transportation.
            $discountAmount = $this->couponEligibility->calculateDiscount($coupon, $eligibleAmount);
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
            'includedAdminMargin' => $includedAdminMargin,
            'discountableSubtotal' => $eligibleAmount,
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
            'assignmentWorkerNetFactor' => $customerServiceAmount > 0.0
                ? max(0.0, min(1.0, $serviceNet / $customerServiceAmount))
                : 1.0,
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
        $coupon = PlatformCoupon::query()->find((int) ($booking->platform_coupon_id ?? 0));
        if (! $coupon instanceof PlatformCoupon) {
            return null;
        }

        $parts = $this->snapshotPricing($booking);

        return $this->allocation(
            $coupon,
            $parts['serviceAmount'],
            $parts['travelFee'],
            $parts['grossAdminMargin'],
            $parts['includedAdminMargin'],
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
            'base_price', 'addons_total', 'travel_fee', 'admin_margin_amount', 'is_pricing_final',
        ]);

        $serviceAmount = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );
        $travelFee = round(max(0.0, (float) ($booking->travel_fee ?? 0)), 2);
        $priorParts = $this->snapshotPricing($booking, true);
        $priorDetails = $booking->getOriginal('property_details');
        $priorSnapshot = is_array($priorDetails) ? ($priorDetails['_coupon_pricing'] ?? null) : null;

        if ($couponWasJustAttached) {
            $grossAdminMargin = round(max(0.0, (float) ($booking->admin_margin_amount ?? 0)), 2);
            if (! (bool) $booking->is_pricing_final && $grossAdminMargin <= 0.0) {
                $grossAdminMargin = (float) $this->pricingCalculator
                    ->provisional($serviceAmount, 0.0)['adminMargin'];
            }
            $originalCustomerTotal = round(max(0.0, (float) (
                $booking->subtotal_before_discount
                ?? $booking->getOriginal('total_price')
                ?? $booking->total_price
            )), 2);
            $includedAdminMargin = min($grossAdminMargin, max(
                0.0,
                $serviceAmount + $travelFee + $grossAdminMargin - $originalCustomerTotal,
            ));
        } elseif ($assignmentPricingWasRecalculated) {
            $grossAdminMargin = $booking->isDirty('admin_margin_amount')
                ? round(max(0.0, (float) $booking->admin_margin_amount), 2)
                : $priorParts['grossAdminMargin'];
            $includedAdminMargin = min($grossAdminMargin, $priorParts['includedAdminMargin']);
        } else {
            $grossAdminMargin = $priorParts['grossAdminMargin'];
            $includedAdminMargin = $priorParts['includedAdminMargin'];
        }

        // Persist financial provenance so later saves and changed pricing settings
        // cannot silently reinterpret the original commission-inclusive quote.
        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $details['_coupon_pricing'] = [
            'grossAdminMargin' => round($grossAdminMargin, 2),
            'includedAdminMargin' => round($includedAdminMargin, 2),
        ];
        $booking->property_details = $details;

        $allocation = $this->allocation(
            $coupon,
            $serviceAmount,
            $travelFee,
            $grossAdminMargin,
            $includedAdminMargin,
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
     * Rebuild the customer quote from its persisted components, without assuming
     * that a commission-inclusive minimum was an additional customer charge.
     *
     * @return array{serviceAmount:float,travelFee:float,grossAdminMargin:float,includedAdminMargin:float}
     */
    private function snapshotPricing(CleaningBooking $booking, bool $original = false): array
    {
        $value = static fn (string $key): mixed => $original
            ? $booking->getOriginal($key)
            : $booking->getAttribute($key);
        $serviceAmount = round(max(0.0, (float) $value('base_price'))
            + max(0.0, (float) $value('addons_total')), 2);
        $travelFee = round(max(0.0, (float) $value('travel_fee')), 2);
        $grossTotal = round(max(0.0, (float) (
            $value('subtotal_before_discount')
            ?? ((float) $value('total_price') + (float) $value('discount_amount'))
        )), 2);
        $details = $value('property_details');
        $snapshot = is_array($details) ? ($details['_coupon_pricing'] ?? null) : null;

        if (is_array($snapshot)
            && isset($snapshot['grossAdminMargin'], $snapshot['includedAdminMargin'])) {
            return [
                'serviceAmount' => $serviceAmount,
                'travelFee' => $travelFee,
                'grossAdminMargin' => round(max(0.0, (float) $snapshot['grossAdminMargin']), 2),
                'includedAdminMargin' => round(max(0.0, (float) $snapshot['includedAdminMargin']), 2),
            ];
        }

        // Old additive quotes can be read directly from the recorded subtotal.
        $additiveMargin = round(max(0.0, $grossTotal - $serviceAmount - $travelFee), 2);
        if ($additiveMargin > 0.0) {
            return [
                'serviceAmount' => $serviceAmount,
                'travelFee' => $travelFee,
                'grossAdminMargin' => $additiveMargin,
                'includedAdminMargin' => 0.0,
            ];
        }

        // Legacy commission-inclusive minimum without an explicit snapshot.
        // Use the recorded net margin/discount, bounded by the configured
        // commission. Do not modify historical rows while reading them.
        $configuredMargin = (float) $this->pricingCalculator
            ->provisional($serviceAmount, 0.0)['adminMargin'];
        $netMargin = max(0.0, (float) $value('admin_margin_amount'));
        $discount = max(0.0, (float) $value('discount_amount'));
        $grossAdminMargin = round(min($configuredMargin, $netMargin + $discount), 2);
        $included = round(min($grossAdminMargin, max(
            0.0,
            $serviceAmount + $travelFee + $grossAdminMargin - $grossTotal,
        )), 2);

        return [
            'serviceAmount' => $serviceAmount,
            'travelFee' => $travelFee,
            'grossAdminMargin' => $grossAdminMargin,
            'includedAdminMargin' => $included,
        ];
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

        $parts = $this->snapshotPricing($booking, true);
        $currentAllocation = $this->allocation(
            $coupon,
            $parts['serviceAmount'],
            $parts['travelFee'],
            $parts['grossAdminMargin'],
            $parts['includedAdminMargin'],
        );
        $grossServiceAmount = $parts['serviceAmount'];
        $grossAdminMargin = $parts['grossAdminMargin'];
        $netAdminMargin = round(max(0.0, (float) ($booking->getOriginal('admin_margin_amount') ?? 0)), 2);

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
            // Travel was never discounted; never gross it up with the service ratio.
            $grossTravelFee = round(max(0.0, (float) ($assignment->travel_fee ?? 0)), 2);
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
