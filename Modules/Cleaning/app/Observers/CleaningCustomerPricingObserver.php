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
        if ((bool) $booking->is_pricing_final) {
            return;
        }

        $this->normalizeCustomerPricing($booking);
    }

    public function updating(CleaningBooking $booking): void
    {
        if (! $this->shouldNormalizeUpdate($booking)) {
            return;
        }

        $this->normalizeCustomerPricing($booking);
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! (bool) $booking->is_pricing_final || ! $this->canNormalize($booking)) {
            return;
        }

        if (! $booking->wasChanged([
            'travel_fee',
            'admin_margin_amount',
            'is_pricing_final',
        ])) {
            return;
        }

        $this->synchronizeWorkerMargins($booking);
    }

    private function shouldNormalizeUpdate(CleaningBooking $booking): bool
    {
        if (! $this->canNormalize($booking)) {
            return false;
        }

        $isPricingFinal = (bool) $booking->is_pricing_final;

        if (! $isPricingFinal) {
            return $booking->isDirty([
                'base_price',
                'addons_total',
                'travel_fee',
                'admin_margin_amount',
                'total_price',
                'is_pricing_final',
            ]);
        }

        // Finalize or recalculate system-derived pricing, but do not overwrite a
        // manually approved final total that only changes base_price/total_price.
        return $booking->isDirty('is_pricing_final')
            || $booking->isDirty('travel_fee')
            || $booking->isDirty('admin_margin_amount');
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

        $pricingCalculator = app(CleaningPricingCalculator::class);
        $workerCount = max(1, (int) ($booking->number_of_workers ?? 1));
        $provisionalTravelFee = (float) $pricingCalculator->provisional(0.0, 0.0, 0.0, $workerCount)['travelFee'];
        $isPricingFinal = (bool) $booking->is_pricing_final;
        $travelFee = $isPricingFinal
            ? round(max(0.0, (float) ($booking->travel_fee ?? 0)), 2)
            : $provisionalTravelFee;
        $includedAdminMarginBase = $pricingCalculator->minimumOrderAdminMarginBase(
            (float) ($booking->base_price ?? 0),
            (string) $booking->property_type,
            (float) ($booking->addons_total ?? 0),
            $travelFee,
            (float) ($booking->total_price ?? 0),
        );
        $pricing = $pricingCalculator->provisional(
            $serviceSubtotal,
            0.0,
            $includedAdminMarginBase,
            $workerCount,
        );
        $adminMargin = (float) $pricing['adminMargin'];

        $booking->travel_fee = $travelFee;
        $booking->admin_margin_amount = $adminMargin;
        $booking->total_price = round(
            (float) $pricing['totalPrice'] - $provisionalTravelFee + $travelFee,
            2,
        );

        if (! $isPricingFinal) {
            $booking->travel_distance_km = null;
        }
    }

    private function canNormalize(CleaningBooking $booking): bool
    {
        // Period V2 quotes are approved by the customer before store and must
        // not be silently changed by legacy provisional repricing.
        $details = (array) ($booking->property_details ?? []);
        if (array_key_exists('_room_period_v2_approved_total', $details)) {
            return false;
        }

        if ((int) ($booking->platform_coupon_id ?? 0) > 0) {
            return false;
        }

        // Recurring parents contain already-priced child visits. Reapplying a
        // single-visit provisional margin would double-count their commission.
        if ($booking->exists && $booking->sessions()->where('session_type', 'recurring_cleaning')->exists()) {
            return false;
        }

        return (float) ($booking->extension_fee_total ?? 0) <= 0.0;
    }

    private function synchronizeWorkerMargins(CleaningBooking $booking): void
    {
        $assignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return;
        }

        $targetMargin = round(max(0.0, (float) ($booking->admin_margin_amount ?? 0)), 2);
        $isEventAssistance = (string) $booking->property_type === 'event_assistance';
        $pricingCalculator = app(CleaningPricingCalculator::class);
        $includedAdminMarginBase = $pricingCalculator->minimumOrderAdminMarginBase(
            (float) ($booking->base_price ?? 0),
            (string) $booking->property_type,
            (float) ($booking->addons_total ?? 0),
            (float) ($booking->travel_fee ?? 0),
            (float) ($booking->total_price ?? 0),
        );
        $serviceSubtotal = round(
            max(0.0, (float) ($booking->base_price ?? 0))
            + max(0.0, (float) ($booking->addons_total ?? 0)),
            2,
        );
        $totalServiceShare = $isEventAssistance
            ? $serviceSubtotal
            : round(
                (float) $assignments->sum(
                    static fn (CleaningBookingWorkerAssignment $assignment): float => max(
                        0.0,
                        (float) ($assignment->service_share_amount ?? 0),
                    ),
                ),
                2,
            );
        $targetIncludedMargin = min(
            $targetMargin,
            (float) $pricingCalculator->provisional(
                $serviceSubtotal,
                0.0,
                $includedAdminMarginBase,
            )['includedAdminMargin'],
        );
        $remainingMargin = $targetMargin;
        $remainingIncludedMargin = $targetIncludedMargin;
        $remainingEventServiceShare = $serviceSubtotal;
        $count = $assignments->count();

        foreach ($assignments->values() as $index => $assignment) {
            $isLast = $index === $count - 1;
            $serviceShare = $isEventAssistance
                ? ($isLast
                    ? round(max(0.0, $remainingEventServiceShare), 2)
                    : round($serviceSubtotal / $count, 2))
                : max(0.0, (float) ($assignment->service_share_amount ?? 0));
            $travelFee = max(0.0, (float) ($assignment->travel_fee ?? 0));

            if ($isEventAssistance) {
                $remainingEventServiceShare = round($remainingEventServiceShare - $serviceShare, 2);
            }

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

            if ($isLast) {
                $includedMargin = round(max(0.0, $remainingIncludedMargin), 2);
            } else {
                $ratio = $totalServiceShare > 0.0
                    ? $serviceShare / $totalServiceShare
                    : 1 / $count;
                $includedMargin = (float) round($targetIncludedMargin * $ratio, 0, PHP_ROUND_HALF_UP);
                $includedMargin = min($includedMargin, max(0.0, $remainingIncludedMargin));
            }

            $remainingIncludedMargin = round($remainingIncludedMargin - $includedMargin, 2);

            $values = [
                'admin_margin_amount' => $margin,
                'worker_amount' => max(
                    0.0,
                    round($serviceShare + $travelFee - $includedMargin, 2),
                ),
            ];

            if ($isEventAssistance) {
                $values['service_share_amount'] = $serviceShare;
                $values['room_count'] = 0;
                $values['rooms_weight'] = 0;
            }

            $assignment->forceFill($values)->saveQuietly();
        }
    }
}
