<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningPricingCalculator;

final class CleaningCustomerPricingObserver
{
    public function creating(CleaningBooking $booking): void
    {
        $this->normalizeCustomerPricing($booking);
    }

    public function updating(CleaningBooking $booking): void
    {
        if (! $booking->isDirty([
            'base_price',
            'addons_total',
            'travel_fee',
            'is_pricing_final',
        ])) {
            return;
        }

        $this->normalizeCustomerPricing($booking);
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged([
            'base_price',
            'addons_total',
            'travel_fee',
            'admin_margin_amount',
            'total_price',
            'is_pricing_final',
        ])) {
            return;
        }

        if (! (bool) $booking->is_pricing_final || ! $this->canNormalize($booking)) {
            return;
        }

        $this->synchronizeWorkerMargins($booking);
    }

    private function normalizeCustomerPricing(CleaningBooking $booking): void
    {
        if (! $this->canNormalize($booking)) {
            return;
        }

        $serviceSubtotal = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );

        $pricing = app(CleaningPricingCalculator::class)->provisional($serviceSubtotal, 0.0);
        $adminMargin = (float) $pricing['adminMargin'];
        $isPricingFinal = (bool) $booking->is_pricing_final;
        $travelFee = $isPricingFinal
            ? round(max(0.0, (float) ($booking->travel_fee ?? 0)), 2)
            : 0.0;

        $booking->travel_fee = $travelFee;
        $booking->admin_margin_amount = $adminMargin;
        $booking->total_price = round($serviceSubtotal + $travelFee + $adminMargin, 2);

        if (! $isPricingFinal) {
            $booking->travel_distance_km = null;
        }
    }

    private function canNormalize(CleaningBooking $booking): bool
    {
        if ((int) ($booking->platform_coupon_id ?? 0) > 0) {
            return false;
        }

        return (float) ($booking->extension_fee_total ?? 0) <= 0.0;
    }

    private function synchronizeWorkerMargins(CleaningBooking $booking): void
    {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return;
        }

        $targetMargin = round(max(0.0, (float) ($booking->admin_margin_amount ?? 0)), 2);
        $totalServiceShare = round(
            (float) $assignments->sum(
                static fn (CleaningBookingWorkerAssignment $assignment): float => max(
                    0.0,
                    (float) ($assignment->service_share_amount ?? 0),
                ),
            ),
            2,
        );
        $remainingMargin = $targetMargin;
        $count = $assignments->count();

        foreach ($assignments->values() as $index => $assignment) {
            $isLast = $index === $count - 1;
            $serviceShare = max(0.0, (float) ($assignment->service_share_amount ?? 0));
            $travelFee = max(0.0, (float) ($assignment->travel_fee ?? 0));

            if ($isLast) {
                $margin = round(max(0.0, $remainingMargin), 2);
            } else {
                $ratio = $totalServiceShare > 0.0
                    ? $serviceShare / $totalServiceShare
                    : 1 / $count;
                $margin = (float) round($targetMargin * $ratio, 0, PHP_ROUND_HALF_UP);
                $margin = min($margin, max(0.0, $remainingMargin));
            }

            $remainingMargin = round($remainingMargin - $margin, 2);

            $assignment->forceFill([
                'admin_margin_amount' => $margin,
                'worker_amount' => max(0.0, round($serviceShare + $travelFee - $margin, 2)),
            ])->saveQuietly();
        }
    }
}
