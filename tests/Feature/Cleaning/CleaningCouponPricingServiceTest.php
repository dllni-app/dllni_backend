<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('uses the administration percentage before reducing worker earnings', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN8', 8);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // The customer still gets 8% of the existing 1,100 total (88). Financially,
    // 8 percentage points come out of the 10% admin share, so worker net is unchanged.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(88.0)
        ->and((float) $booking->admin_margin_amount)->toBe(20.0)
        ->and((float) $booking->total_price)->toBe(1012.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(20.0)
        ->and((float) $assignment->worker_amount)->toBe(900.0);
});

it('does not reduce worker earnings when coupon percentage equals administration percentage', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN10', 10);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(110.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(990.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(900.0);
});

it('reduces worker earnings only by percentage points above the administration percentage', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('WORKER15', 15);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // Admin is 10% of 1,000. A 15% coupon therefore consumes admin's 10 points
    // and only the remaining 5 points (50) reduce the worker's original 900 net.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(165.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(935.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(850.0);
});

it('keeps fixed coupons amount-based with administration first', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningFixedCoupon('FIXED150', 150);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(950.0)
        ->and((float) $assignment->worker_amount)->toBe(850.0);
});

function cleaningCouponBooking(): CleaningBooking
{
    return CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
        'is_pricing_final' => true,
    ]));
}

function cleaningCouponAssignment(CleaningBooking $booking, Worker $worker): CleaningBookingWorkerAssignment
{
    return CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 1000,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'worker_amount' => 900,
        'currency' => 'SYP',
    ]);
}

function cleaningPercentageCoupon(string $code, float $discount): PlatformCoupon
{
    return cleaningCoupon($code, PlatformCoupon::DISCOUNT_PERCENTAGE, $discount);
}

function cleaningFixedCoupon(string $code, float $discount): PlatformCoupon
{
    return cleaningCoupon($code, PlatformCoupon::DISCOUNT_FIXED, $discount);
}

function cleaningCoupon(string $code, string $type, float $discount): PlatformCoupon
{
    return PlatformCoupon::query()->create([
        'code' => $code,
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار توزيع خصم الكوبون',
        'description_en' => 'Coupon allocation test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => $type,
        'discount_value' => $discount,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => null,
        'used_count' => 0,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);
}
