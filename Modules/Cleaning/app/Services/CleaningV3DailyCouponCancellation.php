<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Services\Coupons\PlatformCouponRedemptionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

/** Reverse the one commercial redemption only after every linked day is cancelled. */
final class CleaningV3DailyCouponCancellation
{
    public function reverseIfEligible(CleaningBooking $booking): void
    {
        $origin = data_get($booking->property_details, 'v3_daily_origin_quote_id');
        if ($origin === null) {
            app(PlatformCouponRedemptionService::class)->reverseForOrder(
                $booking, 'v3_unstarted_booking_cancelled', (int) $booking->customer_id,
            );

            return;
        }
        if ($booking->platform_coupon_id === null) {
            return;
        }
        if (DB::transactionLevel() < 1 || $booking->status !== CleaningBookingStatus::Cancelled) {
            throw new DomainException('V3_DAILY_COUPON_CANCELLATION_REVIEW_REQUIRED');
        }
        // The locked shared quote serializes reversal of the one redemption.
        [$quote, $snapshot, $sequence] = app(CleaningV3AcceptedQuoteResolver::class)
            ->load($booking, 'V3_DAILY_COUPON_CANCELLATION_REVIEW_REQUIRED');
        if ($sequence === null || $quote->id !== $origin || ($snapshot['commercialReview']['couponCode'] ?? null) !== $booking->platform_coupon_code) {
            throw new DomainException('V3_DAILY_COUPON_CANCELLATION_REVIEW_REQUIRED');
        }
        $links = DB::table('cleaning_quote_bookings')->where('quote_id', $origin)
            ->orderBy('appointment_sequence')->get();
        if ($links->count() !== count($snapshot['dailyAppointments'])
            || (int) $links->first()?->cleaning_booking_id !== (int) $quote->cleaning_booking_id) {
            throw new DomainException('V3_DAILY_COUPON_CANCELLATION_REVIEW_REQUIRED');
        }
        $siblings = CleaningBooking::query()->whereIn('id', $links->pluck('cleaning_booking_id'))
            ->where('customer_id', $booking->customer_id)->where('contract_version', 'v3')
            ->orderBy('id')->lockForUpdate()->get();
        if ($siblings->count() !== $links->count() || $siblings->contains(fn ($sibling) =>
            data_get($sibling->property_details, 'v3_daily_origin_quote_id') !== $origin
            || (int) $sibling->platform_coupon_id !== (int) $booking->platform_coupon_id)) {
            throw new DomainException('V3_DAILY_COUPON_CANCELLATION_REVIEW_REQUIRED');
        }
        if ($siblings->contains(fn ($sibling) => $sibling->status !== CleaningBookingStatus::Cancelled)) {
            return;
        }
        $anchor = $siblings->firstWhere('id', $quote->cleaning_booking_id);
        CleaningBooking::withoutEvents(fn () => app(PlatformCouponRedemptionService::class)->reverseForOrder(
            $anchor, 'v3_all_daily_bookings_cancelled', (int) $booking->customer_id,
        ));
    }
}
