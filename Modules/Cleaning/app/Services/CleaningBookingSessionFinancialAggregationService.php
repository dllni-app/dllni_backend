<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionFinancialAggregationService
{
    public function sync(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();

        $chargeable = $sessions->reject(function (CleaningBookingSession $session): bool {
            return in_array($this->statusValue($session), [
                CleaningBookingSessionStatus::Cancelled->value,
                CleaningBookingSessionStatus::Skipped->value,
                CleaningBookingSessionStatus::Superseded->value,
            ], true);
        });
        $cancelled = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->statusValue($session) === CleaningBookingSessionStatus::Cancelled->value,
        );

        $basePrice = round((float) $chargeable->sum('base_price'), 2);
        $addonsTotal = round((float) $chargeable->sum('addons_total'), 2);
        $travelFee = round((float) $chargeable->sum('travel_fee'), 2);
        $adminMargin = round((float) $chargeable->sum('admin_margin_amount'), 2);
        $extensionFee = round((float) $chargeable->sum('extension_fee_total'), 2);
        $cancellationFee = round((float) $cancelled->sum('cancellation_fee'), 2);
        $serviceTotal = round((float) $chargeable->sum('total_price'), 2);
        $totalHours = round((float) $chargeable->sum('duration_hours'), 2);
        $grossTotal = round($serviceTotal + $cancellationFee, 2);
        $lockedBooking = CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();
        $discount = min($grossTotal, max(0.0, (float) ($lockedBooking->discount_amount ?? 0)));

        $lockedBooking
            ->forceFill([
                'base_price' => $basePrice,
                'addons_total' => $addonsTotal,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'extension_fee_total' => $extensionFee,
                'cancellation_fee' => $cancellationFee,
                'total_hours' => $totalHours,
                'subtotal_before_discount' => $discount > 0 || $lockedBooking->subtotal_before_discount !== null
                    ? $grossTotal
                    : null,
                'discount_amount' => $discount,
                'total_price' => round($grossTotal - $discount, 2),
            ])->saveQuietly();
    }

    private function statusValue(CleaningBookingSession $session): string
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;
    }
}
